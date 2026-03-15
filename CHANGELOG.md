# Changelog

## 0.0.1 - 2026-03-15
- Rewrite `checkout.php` frontend with Vue 3.
- Rewrite `admin.php` frontend (login + dashboard) with Vue 3.
- Add standalone single-file builder: `scripts/build-single.php`.
- Add Docker image build support (`Dockerfile`, `.dockerignore`).
- Add GitHub Actions pipeline to build standalone artifact + Docker image and create release on tag (`.github/workflows/build-release.yml`).
