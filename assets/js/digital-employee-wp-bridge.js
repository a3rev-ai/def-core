(() => {
  // Minimal bridge script: responds to iframe postMessage with a short-lived JWT for the logged-in WP user
  const allowed = Array.isArray(window.DEWPBridge?.allowedOrigins) ? window.DEWPBridge.allowedOrigins : [];
  const restUrl = window.DEWPBridge?.restUrl || "";
  const nonce = window.DEWPBridge?.nonce || "";

  function isAllowedOrigin(origin) {
    if (!origin) return false;
    if (!allowed || !allowed.length) {
      // If not configured, default-deny
      return false;
    }
    return allowed.includes(origin);
  }

  async function fetchToken() {
    try {
      const res = await fetch(restUrl, {
        method: "GET",
        headers: { "X-WP-Nonce": nonce, "Accept": "application/json" },
        credentials: "same-origin",
      });
      if (!res.ok) return null;
      const data = await res.json();
      return data?.token || null;
    } catch (e) {
      return null;
    }
  }

  window.addEventListener("message", async (event) => {
    try {
      const { origin, data, source } = event;
      if (!source || !data || typeof data !== "object") return;
      if (data?.type !== "a3ai:request-context") return;
      if (!isAllowedOrigin(origin)) return;
      const token = await fetchToken();
      source.postMessage(
        { type: "a3ai:context", token: token || null },
        origin
      );
    } catch (e) {
      // silent
    }
  });
})(); 


