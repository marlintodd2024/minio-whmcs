# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 2.2.x   | :white_check_mark: |
| < 2.2   | :x:                |

## Reporting a Vulnerability

**Do NOT open a public GitHub issue for security vulnerabilities.**

Please report security issues by emailing **security@impulsehosting.com**.

Include:

- Description of the vulnerability
- Steps to reproduce
- Affected version(s)
- Potential impact

We will acknowledge receipt within 48 hours and provide an initial assessment within 5 business days.

## Security Considerations

This module executes shell commands via PHP's `exec()` to interact with the MinIO `mc` CLI. Key security points:

- **All user inputs are sanitized** before being passed to shell commands
- **Bucket names** are validated against S3 naming rules (lowercase alphanumeric + hyphens)
- **Access key operations** verify service ownership before executing
- **CSRF tokens** are validated on all client area form submissions
- **Presigned URLs** expire after 1 hour
- **MinIO credentials** are stored encrypted in WHMCS using its native `encrypt()` function
- **Namespace isolation** — each customer's buckets are prefixed with their username
