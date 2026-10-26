# Rusher Cloud Uploader — Free GitHub Pages + Cloudflare Workers

This version avoids PHP hosting. GitHub Pages serves the UI; a Cloudflare Worker (free tier, no credit card required according to Cloudflare's Workers product page) forwards each selected file to the tfLink upload API.

## Files
- `index.html` — complete Rusher Cloud auto-uploader frontend for GitHub Pages
- `worker.js` — tiny streaming proxy Worker for the upload API

## Deploy the Worker (free, no PHP server)

1. Create a free Cloudflare account at https://dash.cloudflare.com/sign-up.
2. Open **Workers & Pages**: https://dash.cloudflare.com/?to=/:account/workers-and-pages
3. Select **Create application** / **Create Worker** and create a basic Worker.
4. Open **Edit code** (sometimes labelled **Quick edit**), replace the starter code with the full contents of `worker.js`, and click **Deploy**.
5. Copy the Worker URL shown by Cloudflare, e.g. `https://rusher-cloud-upload.YOUR-SUBDOMAIN.workers.dev`.

## Connect GitHub Pages to the Worker

1. Open `index.html`.
2. Find this line near the bottom:
   `const WORKER_UPLOAD_URL = 'PASTE_YOUR_CLOUDFLARE_WORKER_URL_HERE/api/upload';`
3. Replace the placeholder with your actual Worker URL, keeping `/api/upload` at the end. Example:
   `const WORKER_UPLOAD_URL = 'https://rusher-cloud-upload.YOUR-SUBDOMAIN.workers.dev/api/upload';`
4. Commit/push `index.html` to the root of GitHub repository `UploaderFile`. Ensure `index.html` is at the root of the Pages source (not only `README.md`).
5. Open `https://rushercloud.github.io/UploaderFile/` and hard refresh with Ctrl+F5.

## Limits and important notes

- Cloudflare Workers Free currently lists 100,000 requests/day.
- Cloudflare Free plan maximum request body is 100 MB. The frontend uses a 99,000,000-byte per-file limit to leave room for multipart framing.
- tfLink documents 100 MB per file, and anonymous uploads are automatically deleted after 7 days.
- The Worker streams the upload body rather than buffering the entire file in Worker memory.
- The Worker allows browser origins `https://rushercloud.github.io` and local testing origins on port 8000. If your GitHub Pages hostname changes, update `ALLOWED_ORIGINS` in `worker.js` and redeploy.
- CORS origin checks are not authentication: non-browser clients can fake an Origin header. Since this is a public upload tool, the Worker URL can potentially be used by others and could consume quota. Don't use it for confidential files or as an authenticated private service.
- A Cloudflare Worker is serverless code, not a PHP server. This version replaces the PHP backend while retaining GitHub Pages for the interface.
- This package has not been live-deployed or tested against your Cloudflare account. After deployment, test with a small non-sensitive file first.

## Local preview
To preview the static UI, use Python from this folder:
`python -m http.server 8000`
Then open `http://localhost:8000`. Upload requests still require a deployed Worker URL and the local origin is allowed by default.
