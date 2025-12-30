# GitHub Workflows

This directory contains GitHub Actions workflows for automating releases and deployments.

## 📦 Available Workflows

### `release.yml` - Automated Release and S3 Deployment

**Triggers:** Push to `main` or `master` branch

**What it does:**
1. ✅ Extracts version from `digital-employee-wp-bridge.php`
2. ✅ Checks if version tag already exists
3. ✅ Creates new git tag (e.g., `v1.0.0`)
4. ✅ Builds production zip file (excludes dev files)
5. ✅ Uploads ZIP to **private** S3 bucket (no public access)
6. ✅ Generates and uploads changelog.txt to **public** S3 bucket
7. ✅ Invalidates CloudFront cache for changelog
8. ✅ Creates GitHub Release with download links

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

Update both places in the main plugin file:
```php
* Version: 1.0.0  // Plugin header
define( 'DE_WP_BRIDGE_VERSION', '1.0.0' );  // Constant
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

- Tags are created automatically - don't create them manually
- Only unique version numbers will trigger releases
- Pushing the same version again will skip release creation
- All development files are automatically excluded from the zip
- ZIP filename has no version (e.g., `digital-employee-wp-bridge.zip`)
- Each release overwrites the previous ZIP in S3
- Changelog is regenerated and cache invalidated on each release
- Private bucket requires CloudFront authentication for access

## 🆘 Support

For workflow issues, check:
1. Actions tab for detailed logs
2. Verify secrets are configured correctly
3. Ensure version format is correct
4. Review [SETUP.md](./SETUP.md) for troubleshooting tips
