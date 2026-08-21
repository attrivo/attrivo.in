/**
 * Forces HTTP 200 for Meta/Facebook crawlers on GitHub Pages.
 *
 * GitHub Pages sends Accept-Ranges: bytes. Meta's Sharing Debugger sends a
 * Range request and reports Response Code 206. This worker:
 *  1) strips Range / If-Range from the upstream request
 *  2) removes Accept-Ranges from the response
 *  3) rewrites accidental 206 → 200 when the full body is returned
 *
 * Deploy (Cloudflare dashboard or Wrangler), then orange-cloud attrivo.in.
 */
export default {
  async fetch(request) {
    const url = new URL(request.url);

    // Pass through non-GET/HEAD unchanged
    if (request.method !== 'GET' && request.method !== 'HEAD') {
      return fetch(request);
    }

    const headers = new Headers(request.headers);
    headers.delete('Range');
    headers.delete('If-Range');

    const upstream = await fetch(new Request(url.toString(), {
      method: request.method,
      headers,
      redirect: 'manual',
    }));

    const outHeaders = new Headers(upstream.headers);
    outHeaders.delete('Accept-Ranges');
    outHeaders.delete('Content-Range');

    let status = upstream.status;
    if (status === 206) {
      status = 200;
      outHeaders.delete('Content-Range');
    }

    return new Response(upstream.body, {
      status,
      statusText: status === 200 ? 'OK' : upstream.statusText,
      headers: outHeaders,
    });
  },
};
