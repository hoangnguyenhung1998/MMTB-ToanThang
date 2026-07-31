# Phase 11 – Reconciliation, OCR and Excel Integration

## Phase 11.1 foundation

This branch introduces the database foundation for weekly and monthly reconciliation:

- `reconciliation_periods`: reconciliation periods and lifecycle status.
- `machine_daily_assignments`: immutable daily snapshots derived from machine assignment history.
- `reconciliation_rows`: editable OCR/GPS/review data used later for Excel export.
- `ReconciliationGenerator`: rebuilds a period from overlapping `machine_assignments`.

## OCR storage decision

The user never has to provide an image path.

The OCR desktop application will manage a configured data root and send only batch metadata, stored filename, file hash and OCR result. Physical file location will be derived by convention from the data root, group date and batch code.

## Next implementation step

- Period creation UI and validation.
- Historical driver resolution by work date.
- Tests for new machine, transfer and return during a period.
- OCR import batches and image metadata without absolute image paths.
