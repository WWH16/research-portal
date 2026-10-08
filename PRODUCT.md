# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Admins (Research Office staff).** They manage users, colleges/departments and categories. They review concept proposals (pass or return for revision), read the activity log and look after backups.
- **Faculty (proponents).** They submit research projects and upload each stage's document as the project moves forward.

## Product Purpose

The ISU Research Portal tracks Isabela State University research projects from proposal to terminal report. It gives the Research Office one place to review concept proposals, see which colleges have submitted, and keep a history of what changed.

## Operating Context

- A project moves through four stages, Concept, Detailed, Mid-year and Completed, set by the furthest document uploaded. The Research Office never sets the status.
- Only the concept proposal is reviewed: the Research Office passes it or returns it for revision with remarks. The detailed proposal opens once the concept proposal passes; the mid-year progress report opens once the detailed proposal is uploaded; the terminal report opens once the mid-year progress report is uploaded. None of these three is reviewed.
- Proponents upload documents. A new upload replaces the earlier file for that stage.
- Research Drive groups projects by college and year.
- The portal runs on Laravel Cloud. The database and the uploaded-file buckets are Laravel Cloud resources.

## Capabilities and Constraints

- Laravel 13, Livewire 4, Flux and Fortify. Roles are `admin` and `faculty`.
- Only verified accounts reach the portal. Admin pages sit behind the `admin` middleware.
- Uploaded documents live on the private `submissions` disk and profile photos on the `public` disk. Each disk is a Laravel Cloud bucket in production.
- Laravel Cloud backs up the database daily. Its object storage keeps no file backups. The planned portal backup and restore is in `docs/backup-and-restore.md`.
- Tracking starts in 2026. No earlier data exists.

## Brand Commitments

- Name: ISU Research Portal. The university seal is at `public/images/isu_seal.png` (128px variant: `isu_seal-128.png`).
- Interface copy names the Research Office as the contact point.

## Evidence on Hand

- Real seal and share-preview image in `public/images/`.
- No testimonials, usage figures or published metrics exist. Do not invent them.

## Product Principles

- Protect research records. A mistake by an admin or a proponent must not quietly destroy a document.
- Keep the Research Office in control. Faculty act on their own projects only.
- Prefer plain, step-by-step language. Many users are faculty, not IT staff.
