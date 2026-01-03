# Deployment Architecture Summary

## Overview

All Digital Employee plugins use an automated dual-bucket S3 architecture with CloudFront CDN for secure distribution and caching.

## Architecture Diagram

```
GitHub Push (main/master)
        ↓
   GitHub Actions
        ↓
    ┌───────────────────────────┐
    │  Extract Version & Build  │
    └───────────────────────────┘
              ↓
    ┌─────────────────┬─────────────────┐
    ↓                 ↓                 ↓
┌─────────┐   ┌──────────────┐   ┌──────────┐
│ Git Tag │   │ Private S3   │   │ Public S3│
│ v1.0.0  │   │ (ZIP files)  │   │(Changelog)│
└─────────┘   └──────────────┘   └──────────┘
                     ↓                 ↓
              ┌──────────────────────────┐
              │  CloudFront CDN          │
              │  - Auth for ZIPs         │
              │  - Public for Changelog  │
              └──────────────────────────┘
                         ↓
              ┌──────────────────────┐
              │  Cache Invalidation  │
              └──────────────────────┘
                         ↓
              ┌──────────────────────┐
              │   GitHub Release     │
              └──────────────────────┘
```

## Dual-Bucket Strategy

### Private S3 Bucket (Region 1)
- **Purpose:** Store plugin ZIP files
- **Access:** No public access, requires CloudFront authentication
- **Files:** `plugin-name.zip` (no version in filename)
- **Update Strategy:** Overwrite on each release
- **Region:** Configurable via `AWS_REGION_PRIVATE`

### Public S3 Bucket (Region 2)
- **Purpose:** Store changelog files
- **Access:** Public read via CloudFront
- **Files:** `plugin-name/changelog.txt`
- **Update Strategy:** Overwrite and invalidate cache
- **Region:** Configurable via `AWS_REGION_PUBLIC`

## File Naming Convention

### ✅ Current (New) Format
```
def-core.zip              (private bucket)
def-core/changelog.txt    (public bucket)
```

### ❌ Old Format (Removed)
```
def-core-v1.0.0.zip
def-core-latest.zip
```

**Rationale:** Simplifies distribution and cache management. Auto-update systems always fetch the same filename.

## CloudFront Configuration

### Distribution Setup
1. **Origin 1 (Private):** Private S3 bucket with OAI (Origin Access Identity)
2. **Origin 2 (Public):** Public S3 bucket

### Behaviors
```
Default Behavior → Public S3 (changelog access)
Path: /*.zip → Private S3 with authentication
Path: /*/changelog.txt → Public S3 with caching
```

### Cache Invalidation
- Automatically triggered after changelog upload
- Path: `/plugin-name/changelog.txt`
- Ensures users always see latest changelog

## Workflow Steps (Detailed)

### 1. Version Detection
```yaml
VERSION=$(grep -oP "Version:\s*\K[\d\.]+" plugin-file.php)
```
Extracts version from plugin header.

### 2. Tag Check
Verifies if tag `vX.Y.Z` already exists to prevent duplicates.

### 3. Build ZIP
```bash
PLUGIN_NAME="def-core"
ZIP_NAME="${PLUGIN_NAME}.zip"  # No version!
```
Excludes: `.git*`, `node_modules`, `.github`, etc.

### 4. Upload to Private S3
```bash
aws s3 cp plugin.zip \
  s3://${S3_BUCKET_PRIVATE}/plugin.zip \
  --region ${AWS_REGION_PRIVATE}
```

### 5. Generate Changelog
```bash
cat > changelog.txt << 'EOF'
## Version X.Y.Z - 2024-01-15
### Changes
- Released version X.Y.Z
### Download
- https://cloudfront-domain/plugin.zip
EOF
```

### 6. Upload Changelog to Public S3
```bash
aws s3 cp changelog.txt \
  s3://${S3_BUCKET_PUBLIC}/plugin/changelog.txt \
  --region ${AWS_REGION_PUBLIC} \
  --acl public-read \
  --content-type "text/plain; charset=utf-8"
```

### 7. Invalidate CloudFront
```bash
aws cloudfront create-invalidation \
  --distribution-id ${CLOUDFRONT_DISTRIBUTION_ID} \
  --paths "/plugin/changelog.txt"
```

### 8. Create GitHub Release
Attaches both ZIP and changelog, with links to CloudFront URLs.

## GitHub Secrets Required

### Common (All Plugins)
```
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
```

### Private Bucket
```
AWS_REGION_PRIVATE        # e.g., us-east-1
S3_BUCKET_PRIVATE         # e.g., my-private-plugins
```

### Public Bucket
```
AWS_REGION_PUBLIC         # e.g., us-west-2
S3_BUCKET_PUBLIC          # e.g., my-public-plugins
```

### CloudFront
```
CLOUDFRONT_DISTRIBUTION_ID  # e.g., E1234567890ABC
CLOUDFRONT_DOMAIN           # e.g., d1234567890.cloudfront.net
```

## IAM Policy Requirements

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:PutObjectAcl"],
      "Resource": [
        "arn:aws:s3:::private-bucket/*",
        "arn:aws:s3:::public-bucket/*"
      ]
    },
    {
      "Effect": "Allow",
      "Action": ["cloudfront:CreateInvalidation"],
      "Resource": "arn:aws:cloudfront::ACCOUNT-ID:distribution/DIST-ID"
    }
  ]
}
```

## Plugins Configured

1. ✅ **def-core** (main plugin)
2. ✅ **digital-employee-addon-bbpress**
3. ✅ **digital-employee-addon-a3rev-licenses**
4. ✅ **digital-employee-addon-wc-subscriptions**

Each plugin has identical workflow structure with plugin-specific naming.

## Security Features

### Private Bucket Security
- ✅ Block all public access
- ✅ CloudFront OAI only access
- ✅ No direct S3 URLs accessible
- ✅ Authentication required via CloudFront

### Public Bucket Security
- ✅ Read-only public access
- ✅ Only changelog files exposed
- ✅ No sensitive data in changelog
- ✅ Content-Type validation

### IAM Security
- ✅ Minimal permissions (PutObject only)
- ✅ Resource-specific ARNs
- ✅ No DeleteObject permission
- ✅ Separate user for CI/CD

## Monitoring & Troubleshooting

### Check Deployment Status
```bash
# Check if ZIP exists in private bucket
aws s3 ls s3://private-bucket/def-core.zip

# Check changelog in public bucket
aws s3 ls s3://public-bucket/def-core/

# Test CloudFront access
curl -I https://your-cloudfront-domain/def-core/changelog.txt
```

### Common Issues

**CloudFront 403 Error:**
- Check OAI configuration for private bucket
- Verify bucket policies
- Ensure distribution is deployed

**Changelog Not Updating:**
- Wait for invalidation to complete (5-10 minutes)
- Check invalidation status in CloudFront console
- Verify correct path in invalidation request

**Upload Failures:**
- Verify AWS credentials
- Check IAM permissions
- Confirm bucket names and regions match secrets

## Cost Optimization

### S3 Costs
- **Private bucket:** ~$0.023/GB storage + minimal data transfer (through CloudFront)
- **Public bucket:** ~$0.023/GB storage + CloudFront data transfer pricing

### CloudFront Costs
- First 1TB: $0.085/GB
- Cache invalidations: First 1,000/month free, then $0.005/path

### GitHub Actions
- 2,000 minutes/month free (private repos)
- Workflow runs ~2-3 minutes per release

**Estimated monthly cost for 4 plugins with weekly releases:**
- S3: ~$1-2/month
- CloudFront: ~$5-10/month (depending on traffic)
- GitHub Actions: Free tier sufficient

## Backup & Disaster Recovery

### GitHub Releases
- All ZIPs attached to releases (backup)
- Can manually upload if workflow fails

### S3 Versioning (Optional)
```bash
# Enable versioning on private bucket (optional)
aws s3api put-bucket-versioning \
  --bucket private-bucket \
  --versioning-configuration Status=Enabled
```

### Rollback Procedure
1. Download previous version from GitHub release
2. Manually upload to S3 with AWS CLI
3. Create invalidation if needed

## Future Enhancements

- [ ] Automated testing before release
- [ ] Slack/Discord notifications on deployment
- [ ] Multi-region CloudFront distribution
- [ ] Automated rollback on failed deployments
- [ ] Semantic version validation
- [ ] Release notes from commit messages

## Support

For issues or questions:
1. Check workflow logs in GitHub Actions tab
2. Review [SETUP.md](./SETUP.md) for configuration
3. Contact DevOps team

---

**Last Updated:** 2024 (Initial Setup)
**Maintained By:** a3rev Development Team
