const UPSTREAM_API = "https://tmpfile.link/api/upload";
const ALLOWED_ORIGINS = new Set([
  "https://rushercloud.github.io",
  "http://localhost:8000",
  "http://127.0.0.1:8000"
]);
const MAX_BODY_BYTES = 100_000_000; // Cloudflare Free request-body limit is 100 MB

function corsHeaders(origin) {
  const headers = new Headers({
    "Access-Control-Allow-Methods": "POST, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type, Accept",
    "Access-Control-Max-Age": "86400",
    "Vary": "Origin"
  });
  if (ALLOWED_ORIGINS.has(origin)) headers.set("Access-Control-Allow-Origin", origin);
  return headers;
}

function json(data, status, origin) {
  const headers = corsHeaders(origin);
  headers.set("Content-Type", "application/json; charset=utf-8");
  headers.set("Cache-Control", "no-store");
  return new Response(JSON.stringify(data), { status, headers });
}

export default {
  async fetch(request) {
    const origin = request.headers.get("Origin") || "";
    const url = new URL(request.url);

    if (url.pathname !== "/api/upload") {
      return json({ error: "Not found. Endpoint yang tersedia: /api/upload" }, 404, origin);
    }

    if (request.method === "OPTIONS") {
      if (!ALLOWED_ORIGINS.has(origin)) return json({ error: "Origin tidak diizinkan." }, 403, origin);
      return new Response(null, { status: 204, headers: corsHeaders(origin) });
    }

    if (request.method !== "POST") {
      return json({ error: "Gunakan method POST." }, 405, origin);
    }

    // This CORS check protects browser-based use from other origins. It is not
    // authentication: non-browser clients can spoof Origin, so don't treat it
    // as a security boundary for a high-traffic public service.
    if (origin && !ALLOWED_ORIGINS.has(origin)) {
      return json({ error: "Origin tidak diizinkan." }, 403, origin);
    }

    const contentType = request.headers.get("Content-Type") || "";
    if (!contentType.toLowerCase().startsWith("multipart/form-data;")) {
      return json({ error: "Request harus menggunakan multipart/form-data." }, 400, origin);
    }

    const contentLength = Number(request.headers.get("Content-Length") || "0");
    if (contentLength > MAX_BODY_BYTES) {
      return json({ error: "Request melebihi batas 100 MB pada Cloudflare Free." }, 413, origin);
    }

    try {
      // Stream the original multipart body upstream instead of buffering the file
      // into Worker memory. The browser field name must be `file`.
      const upstream = await fetch(UPSTREAM_API, {
        method: "POST",
        headers: {
          "Content-Type": contentType,
          "Accept": "application/json"
        },
        body: request.body
      });

      const body = await upstream.text();
      let parsed;
      try {
        parsed = JSON.parse(body);
      } catch {
        return json({
          error: `API upstream mengembalikan respons bukan JSON (HTTP ${upstream.status}).`,
          upstreamStatus: upstream.status
        }, 502, origin);
      }

      if (!upstream.ok) {
        return json({
          error: parsed.error || parsed.message || `API upstream menolak upload (HTTP ${upstream.status}).`,
          upstreamStatus: upstream.status
        }, upstream.status, origin);
      }

      const downloadLink = parsed.downloadLinkEncoded || parsed.downloadLink;
      if (!downloadLink) {
        return json({ error: "API upstream tidak mengembalikan downloadLink." }, 502, origin);
      }

      return json({
        success: true,
        fileName: parsed.fileName,
        downloadLink: parsed.downloadLink,
        downloadLinkEncoded: parsed.downloadLinkEncoded,
        size: parsed.size,
        type: parsed.type,
        uploadedTo: parsed.uploadedTo
      }, 200, origin);
    } catch (error) {
      return json({
        error: `Gagal menghubungi API upload: ${error?.message || "network error"}`
      }, 502, origin);
    }
  }
};
