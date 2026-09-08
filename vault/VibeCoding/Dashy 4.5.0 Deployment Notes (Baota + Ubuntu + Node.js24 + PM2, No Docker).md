```markdown
Environment: Alibaba Cloud 2‑Core 2G, Baota Panel
Goal: Run Dashy dashboard service, persist after terminal close & server reboot
> Important: We use official pre‑compiled release archive `dashy‑4.5.0.tar.gz`, no source‑code build.

## Software Stack
1. Node.js v24 (Required: Dashy 4.5.0 need >=24.11.0)
2. yarn: Node package manager
3. PM2: Node background process manager
4. Nginx(Baota built‑in): Reverse proxy, handle web access
5. Dashy listen port: **4000 (local only, cannot access from public internet directly)**

## Folder Specification
- Dashy program directory: `/www/wwwroot/dashy`
> ❌ Do NOT use IP site folder `/www/wwwroot/182.92.93.21` for Dashy files
- User config & bookmarks: `/www/wwwroot/dashy/user‑data`
> Backup this folder regularly to avoid losing your settings

## Step 1 Uninstall old Node.js
1. Open Baota Panel → Software Store
2. Uninstall old Node.js v20
3. Open Baota terminal, verify remove complete
```bash
node -v
# Expected output: command not found
```

## Step2 Install Node.js 24
```bash
apt update
apt install curl -y
curl -fsSL https://deb.nodesource.com/setup_24.x | bash -
apt install -y nodejs

# Verify install
node -v
npm -v
# node output should be v24.x.x

# Install yarn globally
npm install -g yarn
```

## Step3 Prepare Dashy directory
```bash
mkdir -p /www/wwwroot/dashy
cd /www/wwwroot/dashy
pwd
# Must output: /www/wwwroot/dashy
```

## Step4 Upload & Extract release archive
1. Baota File Manager → Enter `/www/wwwroot/dashy`
2. Upload file `dashy‑4.5.0.tar.gz`
3. Right‑click file → Extract here
4. Back terminal run ls, confirm `package.json` exists

## Step5 Install runtime dependencies
```bash
# Must inside /www/wwwroot/dashy
yarn install --production
```

## Step6 Manual test run Dashy
```bash
yarn start
```
✅ Expected log:
```
Checking config file against schema...
✔️ Config file is valid, no issues found
Dashy server has started (4000)
```
Press `Ctrl + C` stop test instance.

## Step7 PM2 background service (Critical, prevent stop after terminal close)
```bash
cd /www/wwwroot/dashy

# If old wrong process exists, delete it first
pm2 delete dashy

# Start dashy in correct folder
pm2 start "yarn start" --name dashy

# Save process list for reboot restore
pm2 save

# Set PM2 auto start when server power on
pm2 startup

# Check status
pm2 status
# dashy status must show online

# Check startup logs
pm2 logs dashy --lines 30
```

> ❗Common pitfall: Never run pm2 start inside IP folder, it will cause failure after reboot.

## Step8 Baota Nginx Reverse Proxy Configuration
Dashy only listen local `127.0.0.1:4000`. Public cannot access port 4000 directly.
1. Baota → Websites
2. Edit your site (IP site or domain site)
3. Site Settings → Reverse Proxy → Add proxy
- Proxy name: dashy
- Target URL: `http://127.0.0.1:4000`
Save.

### Two access modes
1. Use raw server IP (No HTTPS)
- Visit: `http://182.92.93.21`
- Limitation: Cannot apply SSL certificate, browser show "Not secure".
- Do NOT fill port number at end of URL.

2. Use custom domain (Recommended, support HTTPS)
- Add DNS A record: your domain → server public IP
- Create website for your domain in Baota
- Reverse proxy target: `http://127.0.0.1:4000`
- SSL tab: Apply Let’s Encrypt certificate, enable Force HTTPS
- Visit `https://your‑domain.com`

## Common Operation Commands
Run inside Baota terminal
```bash
pm2 status                 # Check dashy running status
pm2 logs dashy             # View real‑time log for debug
pm2 restart dashy          # Restart dashy (after manual edit conf.yml)
pm2 stop dashy             # Stop dashy service
pm2 delete dashy           # Remove dashy process from PM2
```

## Trouble Shooting
1. Website not open after close terminal
> Reason: Run pm2 start in wrong directory. Execute `pm2 delete dashy`, cd into `/www/wwwroot/dashy`, start again.

2. Secure Connection Failed
> Raw IP cannot use https, use http://ip; Or configure real domain + SSL certificate.

3. Port 4000 cannot access from browser
> Normal behaviour, Dashy bind localhost only, access via Nginx reverse proxy instead.

4. Service crashed after server reboot
> Check two points:
> 1. pm2 startup executed
> 2. pm2 save executed
> 3. dashy started in `/www/wwwroot/dashy` folder

## Memory Tip for 2G server
Stop MySQL service in Baota software store if you are not using database, free RAM, avoid OOM kill terminate Dashy silently.

## Upgrade Dashy later
1. Stop service: `pm2 stop dashy`
2. Backup `/www/wwwroot/dashy/user‑data`
3. Upload new release tar.gz, overwrite program files, keep user‑data folder.
4. Run `yarn install --production`
5. `pm2 start dashy`