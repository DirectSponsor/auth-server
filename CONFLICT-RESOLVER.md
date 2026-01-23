# Syncthing Conflict Resolver System

**System Status:** ✅ Active on Auth Server (`es3-auth`)  
**Deployed:** 2026-01-22  
**Script Path:** `/var/www/auth.directsponsor.org/public_html/api/resolve-conflicts.php`  
**Log Path:** `/var/directsponsor-data/logs/conflict-resolver.log`  
**Cron Schedule:** Every 10 minutes

## 1. Problem Overview
The "DirectSponsor" network (ClickForCharity, ROFLFaucet, etc.) uses **Syncthing** to synchronize user balance files (`userdata/balances/{userid}.txt`) across multiple servers.

**The Race Condition:**
1.  User earns coins on Site A.
2.  User immediately switches to Site B (triggering a persistent "flush" on Site A).
3.  User earns coins on Site B *before* Syncthing has propagated the update from Site A.
4.  Site B writes a new balance file.
5.  Syncthing detects two "new" versions of the file and creates a **conflict file** (e.g., `user.sync-conflict-20260118...txt`) to avoid data loss.
6.  **Result:** The user "loses" the earnings from the conflict file because the application only reads the main file.

## 2. The Solution: `resolve-conflicts.php`
We implemented an automated PHP script that runs on the **Auth Server** (the central hub) to resolve these conflicts.

### How it functionality works:
1.  **Scan:** Looks for files matching `*sync-conflict*` in the balances directory.
2.  **Analyze:** Parses the conflict file and the current main balance file.
3.  **Merge:**
    *   Iterates through `recent_transactions` in the conflict file.
    *   Checks if each transaction (by Timestamp + Amount + Type) is missing from the main file.
    *   If a transaction is missing, it is **added** to the main file and the balance is updated.
4.  **Archive:** The conflict file is moved to `conflicts_archive/` to clean the directory.
5.  **Log:** All actions are logged for audit purposes.

## 3. Server Configuration (Auth Server)
The script is deployed on `es3-auth` because it acts as the central truth for user data.

*   **Script Location:** `/var/www/auth.directsponsor.org/public_html/api/resolve-conflicts.php`
*   **Data Directory:** `/var/directsponsor-data/userdata/balances/`
*   **Logs:** `/var/directsponsor-data/logs/conflict-resolver.log`

### Cron Job
The script runs every 10 minutes:
```bash
*/10 * * * * php /var/www/auth.directsponsor.org/public_html/api/resolve-conflicts.php >> /var/directsponsor-data/logs/conflict-resolver.log 2>&1
```

## 4. Troubleshooting & Verification

### How to check if it's working
1.  **Check the logs:**
    ```bash
    ssh es3-auth "tail -f /var/directsponsor-data/logs/conflict-resolver.log"
    ```
    *   *Normal output:* `Found 0 conflict files...`
    *   *Action output:* `+ Merging transaction: 50 coins...`

2.  **Manually trigger a scan:**
    ```bash
    ssh es3-auth "php /var/www/auth.directsponsor.org/public_html/api/resolve-conflicts.php"
    ```

3.  **Dry-Run Mode (Safe Test):**
    To see what *would* happen without changing any files:
    ```bash
    ssh es3-auth "php /var/www/auth.directsponsor.org/public_html/api/resolve-conflicts.php dry-run"
    ```

## 5. Maintenance
*   **Log Rotation:** The log file can grow large. Ensure standard log rotation is set up for `/var/directsponsor-data/logs/*.log`.
*   **Archive Cleanup:** The `conflicts_archive/` folder will accumulate files. These are safe to delete after a few months if disk space is needed.
