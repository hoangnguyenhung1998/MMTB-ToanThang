from __future__ import annotations

from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageOps


def read_image(path: Path) -> np.ndarray:
    with Image.open(path) as image:
        image = ImageOps.exif_transpose(image).convert("RGB")
        array = np.array(image)
    return cv2.cvtColor(array, cv2.COLOR_RGB2BGR)


def rotate(image: np.ndarray, angle: int) -> np.ndarray:
    if angle == 90:
        return cv2.rotate(image, cv2.ROTATE_90_CLOCKWISE)
    if angle == 180:
        return cv2.rotate(image, cv2.ROTATE_180)
    if angle == 270:
        return cv2.rotate(image, cv2.ROTATE_90_COUNTERCLOCKWISE)
    return image


def region(image: np.ndarray, x1: float, y1: float, x2: float, y2: float) -> np.ndarray:
    height, width = image.shape[:2]
    xa, ya = max(0, int(width * x1)), max(0, int(height * y1))
    xb, yb = min(width, int(width * x2)), min(height, int(height * y2))
    return image[ya:yb, xa:xb]


def enhance_for_ocr(image: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
    height, width = gray.shape[:2]
    if max(height, width) < 1200:
        scale = min(2.0, 1200 / max(height, width))
        gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    clahe = cv2.createCLAHE(clipLimit=2.2, tileGridSize=(8, 8))
    return clahe.apply(gray)


def flatten_ocr_result(result) -> tuple[list[str], list[float]]:
    texts: list[str] = []
    scores: list[float] = []
    if result is None:
        return texts, scores
    for text_attr, score_attr in (("txts", "scores"), ("texts", "scores")):
        raw_texts = getattr(result, text_attr, None)
        raw_scores = getattr(result, score_attr, None)
        if raw_texts is not None:
            texts = [str(value) for value in raw_texts if str(value).strip()]
            scores = [float(value) for value in (raw_scores or [])]
            return texts, scores
    if isinstance(result, dict):
        texts = [str(value) for value in (result.get("txts") or result.get("texts") or [])]
        scores = [float(value) for value in (result.get("scores") or [])]
        return texts, scores
    rows = result[0] if isinstance(result, tuple) and result else result
    if isinstance(rows, (list, tuple)):
        for row in rows:
            try:
                if isinstance(row, dict):
                    text = row.get("text") or row.get("txt") or ""
                    score = row.get("score", 0)
                else:
                    text, score = row[1], row[2]
                if str(text).strip():
                    texts.append(str(text))
                    scores.append(float(score))
            except (IndexError, KeyError, TypeError, ValueError):
                continue
    return texts, scores


def table_line_score(image: np.ndarray) -> float:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
    binary = cv2.adaptiveThreshold(
        gray,
        255,
        cv2.ADAPTIVE_THRESH_MEAN_C,
        cv2.THRESH_BINARY_INV,
        31,
        12,
    )
    height, width = gray.shape[:2]
    horizontal_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (max(20, width // 20), 1))
    vertical_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(20, height // 20)))
    horizontal = cv2.morphologyEx(binary, cv2.MORPH_OPEN, horizontal_kernel)
    vertical = cv2.morphologyEx(binary, cv2.MORPH_OPEN, vertical_kernel)
    line_pixels = cv2.countNonZero(cv2.bitwise_or(horizontal, vertical))
    ratio = line_pixels / max(1, height * width)
    return min(1.0, ratio / 0.035)
