# GitHub Workflows

This directory contains GitHub Actions workflows for automating releases and deployments.

## 📦 Available Workflows

### `release.yml` - Automated Release and S3 Deployment

**Triggers:** Push to `main` or `master` branch

**What it does on every commit:**
1. ✅ Extracts version from `digital-employee-wp-bridge.php`
2. ✅ Checks if version tag already exists
3. ✅ Deletes existing release and tag if version exists (to recreate with latest code)
4. ✅ Builds production zip file (excludes dev files)
5. ✅ Uploads ZIP to **private** S3 bucket (no public access)
6. ✅ Uploads changelog.txt from repository to **public** S3 bucket
7. ✅ Invalidates CloudFront cache for changelog
8. ✅ Creates new git tag (e.g., `v1.0.0`)
9. ✅ Creates GitHub Release with download links

## 🚀 Quick Start

1. **Set up AWS credentials** - See [SETUP.md](./SETUP.md) for detailed instructions
2. **Update plugin version** in `digital-employee-wp-bridge.php`
3. **Commit and push** to main branch
4. **Workflow runs automatically** - Check Actions tab

## 📋 Required Secrets

Add these in GitHub repository settings:

**AWS Credentials:**
- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`

**Private S3 (ZIP files):**
- `AWS_REGION_PRIVATE`
- `S3_BUCKET_PRIVATE`

**Public S3 (Changelogs):**
- `AWS_REGION_PUBLIC`
- `S3_BUCKET_PUBLIC`

**CloudFront:**
- `CLOUDFRONT_DISTRIBUTION_ID`
- `CLOUDFRONT_DOMAIN`

## 📖 Documentation

- [Complete Setup Guide](./SETUP.md)
- [GitHub Actions Documentation](https://docs.github.com/en/actions)

## 🔧 Version Format

Use semantic versioning: `MAJOR.MINOR.PATCH` (e.g., `1.0.0`, `1.2.3`)

Update three places before release:

**1. Plugin header:**
```php
* Version: 1.0.0
```

**2. Version constant:**
```php
define( 'DE_WP_BRIDGE_VERSION', '1.0.0' );
```

**3. changelog.txt:**
```txt
= 1.0.0 - 2026-01-02 =
* Initial release
```

## 📦 Download Locations

After successful deployment, files are available at:

**Private S3 Bucket (requires authentication):**
- ZIP: `s3://private-bucket/digital-employee-wp-bridge/digital-employee-wp-bridge.zip`
- CloudFront: `https://your-cloudfront-domain/digital-employee-wp-bridge/digital-employee-wp-bridge.zip`

**Public S3 Bucket (publicly accessible):**
- Changelog: `s3://public-bucket/digital-employee-wp-bridge/changelog.txt`
- CloudFront: `https://your-cloudfront-domain/digital-employee-wp-bridge/changelog.txt`

**GitHub Release:**
- Attached to the release on GitHub

## ⚠️ Important Notes

- **Every commit fully updates everything** - S3, tags, and GitHub releases
- Existing tags/releases are deleted and recreated with latest code
- All development files are automatically excluded from the zip
- ZIP filename has no version (e.g., `digital-employee-wp-bridge.zip`)
- **Each commit overwrites the previous ZIP in S3** - Always latest code
- **Update changelog.txt in repository before committing**
- Changelog is uploaded and cache invalidated on each commit
- Private bucket requires CloudFront authentication for access

### Workflow Behavior

| Scenario | Delete Old | Build ZIP | Upload S3 | Create Tag | Create Release |
|----------|------------|-----------|-----------|------------|----------------|
| **First release** (tag doesn't exist) | ❌ Skip | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes |
| **Update existing version** | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes |
| **Bug fix, same version** | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes |

**Every commit = Complete update of S3, GitHub tag, and GitHub release!** 🚀

## 🆘 Support

For workflow issues, check:
1. Actions tab for detailed logs
2. Verify secrets are configured correctly
3. Ensure version format is correct
4. Review [SETUP.md](./SETUP.md) for troubleshooting tips
