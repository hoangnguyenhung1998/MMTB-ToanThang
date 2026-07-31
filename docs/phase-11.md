# Phase 11 - Reconciliation Module

## Objective

Implement a complete reconciliation module for machine working hours.

This phase must NOT change existing machine management workflow.

The reconciliation module is an independent business layer.

---

# Business Goal

Compare:

- GPS working hours
- Driver logbook
- Actual machine assignment

Generate a reconciliation result for each working day.

---

# Source of Truth

Database (MySQL)

Excel is export only.

Python OCR application is separated.

Never move OCR logic into Laravel.

---

# Main Entities

- reconciliation_periods
- reconciliation_rows

Related entities:

- machines
- machine_assignments
- machine_events
- machine_driver_histories
- projects
- command_centers
- drivers
- users

---

# Workflow

Period

Draft

↓

Generate rows

↓

Review

↓

Confirm

↓

Locked

↓

Export Excel

---

# Row Status

draft

↓

reviewed

↓

confirmed

↓

locked

---

# Period Status

draft

↓

generated

↓

reviewing

↓

confirmed

↓

locked

---

# Required Features

## Period

- create
- regenerate
- delete draft
- view summary

---

## Row

- compare GPS
- compare logbook
- calculate difference
- note reason
- mark reviewed
- mark confirmed

---

## Review

Reviewer can:

- accept
- reject
- comment

---

## Confirm

Manager confirms reviewed rows.

Only reviewed rows may be confirmed.

---

## Lock

Locked period:

- read only
- cannot regenerate
- cannot edit
- cannot delete

---

## Export

Export Excel.

Do NOT write back into database.

---

# UI

Pages

Period list

↓

Period detail

↓

Row detail

↓

Review

↓

Export

---

# Rules

Never modify historical records.

Never overwrite machine history.

Keep all history.

---

# Non Goals

Do NOT

- change OCR
- change Machine module
- change Assignment workflow
- change Driver history
- save image paths
- modify unrelated modules

---

# Coding

Laravel 11

Service Layer

Form Request

Policy

Blade

Route Model Binding

Transactions where needed.

---

# Testing

Ignore default Laravel Breeze authentication failures.

Focus only on Phase 11.
