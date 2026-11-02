# Trash System Implementation Guide

## 🎯 Overview
Replace direct file deletions with trash system to ensure deletions sync across all sites and provide 30-day recovery.

## 📁 Files Created
1. `trash_system.php` - Core trash functions
2. `cleanup-trash.sh` - Daily cleanup script
3. `IMPLEMENTATION_GUIDE.md` - This file

## 🔧 Implementation Steps

### Step 1: Deploy Trash Functions
Copy `trash_system.php` to all sites:
```bash
scp trash_system.php es7-roflfaucet:/var/www/html/includes/
scp trash_system.php es3-clickforcharity:/var/www/html/includes/
```

### Step 2: Update Existing Code

#### **ROFLFaucet - User Deletion**
Find files that use `unlink()` and replace:

```php
// OLD CODE (in user management):
unlink("/var/roflfaucet-data/userdata/balances/$user_id.txt");
unlink("/var/roflfaucet-data/userdata/profiles/$user_id.txt");
unlink("/var/roflfaucet-data/userdata/tokens/$user_id.txt");

// NEW CODE:
require_once 'includes/trash_system.php';
moveToTrash("/var/roflfaucet-data/userdata/balances/$user_id.txt", "/var/roflfaucet-data");
moveToTrash("/var/roflfaucet-data/userdata/profiles/$user_id.txt", "/var/roflfaucet-data");
moveToTrash("/var/roflfaucet-data/userdata/tokens/$user_id.txt", "/var/roflfaucet-data");
```

#### **ClickForCharity - User Deletion**
Same pattern for CFC files:
```php
require_once 'includes/trash_system.php';
moveToTrash("/var/clickforcharity-data/userdata/balances/$user_id.txt", "/var/clickforcharity-data");
moveToTrash("/var/clickforcharity-data/userdata/profiles/$user_id.txt", "/var/clickforcharity-data");
```

### Step 3: Setup Daily Cleanup
Add to cron on each server:
```bash
# Deploy cleanup script
scp cleanup-trash.sh es7-roflfaucet:/usr/local/bin/
scp cleanup-trash.sh es3-clickforcharity:/usr/local/bin/

# Make executable and add to cron
ssh es7-roflfaucet 'chmod +x /usr/local/bin/cleanup-trash.sh'
ssh es7-roflfaucet 'echo "0 2 * * * /usr/local/bin/cleanup-trash.sh" | crontab -'

ssh es3-clickforcharity 'chmod +x /usr/local/bin/cleanup-trash.sh'
ssh es3-clickforcharity 'echo "0 2 * * * /usr/local/bin/cleanup-trash.sh" | crontab -'
```

### Step 4: Test Implementation

#### **Test User Deletion**
1. Create test user on ROFLFaucet
2. Delete the user
3. Verify files moved to trash: `ls -la /var/roflfaucet-data/userdata/.trash/$(date +%Y-%m-%d)/`
4. Check files appear in trash on other sites (via sync)

#### **Test Recovery**
```php
// Test restore function
require_once 'includes/trash_system.php';
restoreFromTrash(
    "/var/roflfaucet-data/userdata/.trash/2025-11-24/balances/123.txt.1732440000",
    "/var/roflfaucet-data/userdata/balances/123.txt"
);
```

#### **Test Cleanup**
```bash
# Run manually to test
/usr/local/bin/cleanup-trash.sh
tail -f /var/log/trash-cleanup.log
```

## 🔍 Files to Update

### **ROFLFaucet** (search for `unlink`):
```bash
# Find files that delete user data
grep -r "unlink.*userdata" /var/www/html/api/
grep -r "unlink.*balance" /var/www/html/api/
grep -r "unlink.*profile" /var/www/html/api/
```

Likely locations:
- `/api/user-management.php`
- `/api/admin/delete-user.php`
- `/api/profile-management.php`

### **ClickForCharity** (same pattern):
```bash
grep -r "unlink.*userdata" /var/www/html/api/
```

## 📊 Monitoring

### **Check Trash Size**
```bash
# Current trash usage
du -sh /var/roflfaucet-data/userdata/.trash/
du -sh /var/clickforcharity-data/userdata/.trash/

# List recent deletions
ls -la /var/roflfaucet-data/userdata/.trash/$(date +%Y-%m-%d)/
```

### **Cleanup Logs**
```bash
tail -f /var/log/trash-cleanup.log
```

## ✅ Verification Checklist

- [ ] Trash functions deployed to all sites
- [ ] User deletion code updated to use trash system
- [ ] Daily cleanup script installed via cron
- [ ] Test deletion creates trash files
- [ ] Test trash syncs to other sites
- [ ] Test recovery from trash works
- [ ] Test cleanup removes old files
- [ ] Monitor logs for errors

## 🚨 Rollback Plan

If issues occur, rollback by:
1. Restore original `unlink()` calls
2. Remove trash_system.php files
3. Stop cron cleanup jobs
4. Manually restore needed files from trash

## 📈 Benefits Achieved

✅ **Deletions sync across sites** (no more inconsistent state)  
✅ **30-day recovery window** (protects against accidental deletions)  
✅ **Audit trail** (all deletions logged with timestamps)  
✅ **No sync errors** (files moved, not deleted)  
✅ **Automatic cleanup** (old files removed daily)  

---

**Result**: Robust deletion system that syncs properly and protects user data! 🎯
