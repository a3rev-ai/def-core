# GitHub Actions Setup Guide

This document explains how to set up the automated release workflow for the Digital Employee WordPress Bridge plugin.

## Overview

The workflow automatically on **every commit**:
1. Detects version in the main plugin file
2. Checks if version tag already exists on GitHub
3. **If tag exists:** Deletes existing GitHub release and tag
4. Builds a production-ready zip file
5. Uploads the zip to private Amazon S3 bucket (no public access)
6. Uploads changelog.txt from repository to public S3 bucket
7. Invalidates CloudFront cache for the changelog
8. Creates a git tag for the version (fresh or recreated)
9. Creates a GitHub release with download links

**This means:**
- 🔄 Every commit fully updates S3, tags, and GitHub releases
- 🏷️ Tags and releases are recreated if they already exist
- 📦 You can push bug fixes without bumping version (everything gets updated)
- 🆕 Bump version when you want a new version number

## Architecture

This workflow uses a **dual-bucket architecture**:

- **Private S3 Bucket**: Stores plugin zip files (no public access, behind CloudFront authentication)
- **Public S3 Bucket**: Stores changelog.txt files (publicly accessible via CloudFront)

## Required GitHub Secrets

Navigate to your repository settings → Secrets and variables → Actions, and add the following secrets:

### AWS Credentials

| Secret Name | Description | Example |
|-------------|-------------|---------|
| `AWS_ACCESS_KEY_ID` | Your AWS Access Key ID | `AKIAIOSFODNN7EXAMPLE` |
| `AWS_SECRET_ACCESS_KEY` | Your AWS Secret Access Key | `wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY` |

### Private S3 Bucket (ZIP Files)

| Secret Name | Description | Example |
|-------------|-------------|---------|
| `AWS_REGION_PRIVATE` | AWS region for private S3 bucket | `us-east-1` |
| `S3_BUCKET_PRIVATE` | Private S3 bucket name | `my-private-plugins-bucket` |

### Public S3 Bucket (Changelog)

| Secret Name | Description | Example |
|-------------|-------------|---------|
| `AWS_REGION_PUBLIC` | AWS region for public S3 bucket | `us-west-2` |
| `S3_BUCKET_PUBLIC` | Public S3 bucket name | `my-public-plugins-bucket` |

### CloudFront

| Secret Name | Description | Example |
|-------------|-------------|---------|
| `CLOUDFRONT_DISTRIBUTION_ID` | CloudFront distribution ID | `E1234567890ABC` |
| `CLOUDFRONT_DOMAIN` | CloudFront domain name | `d1234567890.cloudfront.net` |

### How to Create AWS Credentials

1. Log in to AWS Console
2. Navigate to IAM → Users
3. Create a new user or select existing user
4. Attach custom IAM policy (see below)
5. Generate Access Keys under Security Credentials tab
6. Copy the Access Key ID and Secret Access Key

### Required IAM Policy

Attach this policy to your IAM user for both S3 and CloudFront access:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:PutObjectAcl"
      ],
      "Resource": [
        "arn:aws:s3:::YOUR-PRIVATE-BUCKET/*",
        "arn:aws:s3:::YOUR-PUBLIC-BUCKET/*"
      ]
    },
    {
      "Effect": "Allow",
      "Action": [
        "cloudfront:CreateInvalidation"
      ],
      "Resource": "arn:aws:cloudfront::YOUR-ACCOUNT-ID:distribution/YOUR-DISTRIBUTION-ID"
    }
  ]
}
```

### S3 Bucket Configuration

**Private S3 Bucket (ZIP files):**
- ❌ No public access (block all public access)
- ✅ CloudFront OAI (Origin Access Identity) configured
- Structure: `s3://private-bucket/plugin-name/plugin-name.zip` (no version in filename)

**Public S3 Bucket (Changelog):**
- ✅ Public read access via bucket policy
- ✅ Accessible through CloudFront
- Structure: `s3://public-bucket/plugin-name/changelog.txt`

**Example Bucket Policy for Public Bucket:**
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "PublicReadGetObject",
      "Effect": "Allow",
      "Principal": "*",
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::YOUR-PUBLIC-BUCKET/*"
    }
  ]
}
```

## Workflow Triggers

The workflow runs automatically on:
- Push to `main` branch
- Push to `master` branch

## CloudFront Configuration

### Setup CloudFront Distribution

1. Create a CloudFront distribution for your public S3 bucket
2. Configure origin access for private bucket (OAI)
3. Note the Distribution ID (e.g., `E1234567890ABC`)
4. Note the CloudFront domain (e.g., `d1234567890.cloudfront.net`)

### Behaviors Configuration

- **Default:** Points to public bucket (changelog access)
- **Path pattern `/plugin-name.zip`:** Points to private bucket with authentication

## Version Management

To release a new version:

1. Update the version in `digital-employee-wp-bridge.php`:
   ```php
   * Version: 1.0.1
   ```

2. Update the VERSION constant:
   ```php
   define( 'DE_WP_BRIDGE_VERSION', '1.0.1' );
   ```

3. Update `changelog.txt` with the new version details:
   ```txt
   = 1.0.1 - 2026-01-02 =
   * Added feature X
   * Fixed bug Y
   * Improved performance
   ```

4. Update `changelog.txt` in the repository root:
   ```txt
   = 1.0.1 - 2026-01-02 =
   * Added new feature X
   * Fixed bug Y
   * Improved performance Z
   ```

5. Commit and push to main/master branch:
   ```bash
   git add digital-employee-wp-bridge.php changelog.txt
   git commit -m "Bump version to 1.0.1"
   git push origin main
   ```

6. The workflow will automatically:
   - Check if tag `v1.0.1` exists
   - **Delete existing release and tag if they exist**
   - Build `digital-employee-wp-bridge.zip` (no version in filename)
   - Upload ZIP to **private** S3 bucket
   - Upload `changelog.txt` from repository to **public** S3 bucket
   - Invalidate CloudFront cache for changelog
   - **Create tag `v1.0.1`** (fresh or recreated)
   - **Create GitHub release** (fresh or recreated)

### Updating Code Without Version Bump

You can push bug fixes or updates without changing the version:

```bash
# Make your code changes
git add .
git commit -m "Fix minor bug in admin panel"
git push origin main
```

**Result:** 
- Existing tag/release for current version are deleted
- S3 gets updated with latest code
- Tag and release are recreated with latest code
- Users always get the latest version from the same version number!

## Files Excluded from Zip

The following files/directories are automatically excluded from the production zip:
- `.git` and `.gitignore`
- `node_modules/`
- `.bit/`
- `package-lock.json`
- `.DS_Store` and `._*`
- `*.log` files
- `.github/` directory

## S3 File Structure

### Private S3 Bucket (ZIP Files)
```
s3://private-bucket/
  ├── digital-employee-wp-bridge/
  │   └── digital-employee-wp-bridge.zip
  ├── digital-employee-addon-bbpress/
  │   └── digital-employee-addon-bbpress.zip
  ├── digital-employee-addon-a3rev-licenses/
  │   └── digital-employee-addon-a3rev-licenses.zip
  └── digital-employee-addon-wc-subscriptions/
      └── digital-employee-addon-wc-subscriptions.zip
```

### Public S3 Bucket (Changelogs)
```
s3://public-bucket/
  ├── digital-employee-wp-bridge/
  │   └── changelog.txt
  ├── digital-employee-addon-bbpress/
  │   └── changelog.txt
  ├── digital-employee-addon-a3rev-licenses/
  │   └── changelog.txt
  └── digital-employee-addon-wc-subscriptions/
      └── changelog.txt
```

### Access URLs (via CloudFront)
```
https://your-cloudfront-domain/digital-employee-wp-bridge/digital-employee-wp-bridge.zip (private, requires auth)
https://your-cloudfront-domain/digital-employee-wp-bridge/changelog.txt (public)
```

## Troubleshooting

### Workflow fails at "Create and push tag"
- Ensure `GITHUB_TOKEN` has write permissions
- Check if tag already exists

### Workflow fails at "Upload ZIP to Private S3 Bucket"
- Verify AWS credentials are correct
- Ensure private S3 bucket exists
- Check `AWS_REGION_PRIVATE` matches bucket location
- Verify IAM user has `s3:PutObject` permission

### Workflow fails at "Upload Changelog to Public S3 Bucket"
- Verify public S3 bucket exists
- Check `AWS_REGION_PUBLIC` matches bucket location
- Ensure IAM user has `s3:PutObject` and `s3:PutObjectAcl` permissions
- Verify bucket policy allows public-read ACL

### Workflow fails at "Invalidate CloudFront Cache"
- Verify `CLOUDFRONT_DISTRIBUTION_ID` is correct
- Ensure IAM user has `cloudfront:CreateInvalidation` permission
- Check that CloudFront distribution exists and is enabled

### Changelog not updating on CloudFront
- Wait a few minutes for invalidation to complete
- Check CloudFront invalidations in AWS Console
- Verify the invalidation path matches: `/plugin-name/changelog.txt`

### Cannot access ZIP file via CloudFront
- ZIP files are in private bucket and require authentication
- Verify CloudFront origin access identity (OAI) is configured
- Check CloudFront behaviors and origin settings

### Tag already exists but needs to be recreated
```bash
# Delete tag locally and remotely
git tag -d v1.0.0
git push origin :refs/tags/v1.0.0

# Push again to trigger workflow
git commit --allow-empty -m "Trigger release for v1.0.0"
git push origin main
```

### Testing CloudFront URLs
```bash
# Test public changelog (should work)
curl -I https://your-cloudfront-domain/digital-employee-wp-bridge/changelog.txt

# Test private ZIP (will require authentication)
curl -I https://your-cloudfront-domain/digital-employee-wp-bridge/digital-employee-wp-bridge.zip
```

## Testing

To test the workflow without creating a release:
1. Create a feature branch
2. Modify the workflow to trigger on your branch
3. Push changes and monitor the Actions tab

## Security Best Practices

1. ✅ Never commit AWS credentials to the repository
2. ✅ Use IAM roles with minimal required permissions
3. ✅ Rotate AWS access keys regularly
4. ✅ Enable MFA on AWS account
5. ✅ Use separate AWS accounts for production/staging

## Support

For issues with the workflow:
1. Check the Actions tab for detailed logs
2. Verify all secrets are configured correctly
3. Ensure version number format is correct (e.g., `1.0.0`)
4. Contact the development team

## Related Documentation

- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [AWS S3 Documentation](https://docs.aws.amazon.com/s3/)
- [GitHub Releases](https://docs.github.com/en/repositories/releasing-projects-on-github)
