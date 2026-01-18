# Auth Server TODOs

## Deployment
- [ ] **Fix Remote Permissions User**: The `deploy.sh` script attempts to `chown` files to `www-data:www-data`, but the remote server returns `invalid user`.
    - **Error**: `chown: invalid user: ‘www-data:www-data’`
    - **Location**: `deploy.sh` -> `fix_permissions()` function.
    - **Action**: Identify the correct web server user on the remote host (e.g., `apache`, `nginx`, `nobody`) and update `deploy.sh`.
