(() => {
  // Bridge script: responds to iframe postMessage with JWT for the logged-in WP user
  // Also handles inline login requests (Loop 6)
  const allowed = Array.isArray(window.DEFCore?.allowedOrigins) ? window.DEFCore.allowedOrigins : [];
  const restUrl = window.DEFCore?.restUrl || "";
  const loginUrl = window.DEFCore?.loginUrl || "";
  const siteUrl = window.DEFCore?.siteUrl || "";
  const nonce = window.DEFCore?.nonce || "";

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

  async function performLogin(username, password) {
    // Perform login via WordPress AJAX
    try {
      if (!loginUrl) {
        return { success: false, error: "Login endpoint not configured" };
      }

      const formData = new FormData();
      formData.append("action", "def_core_inline_login");
      formData.append("log", username);
      formData.append("pwd", password);
      formData.append("_wpnonce", nonce);

      const res = await fetch(loginUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      });

      const data = await res.json();

      if (data?.success) {
        // Login successful - token is included in response
        // (cookies won't be available for JS fetch until browser processes response)
        return { success: true, token: data?.data?.token || null };
      } else {
        return {
          success: false,
          error: data?.data?.message || "Login failed — please check your details and try again."
        };
      }
    } catch (e) {
      return { success: false, error: "Unable to log in right now. Please try again." };
    }
  }

  window.addEventListener("message", async (event) => {
    try {
      const { origin, data, source } = event;
      if (!source || !data || typeof data !== "object") return;
      if (!isAllowedOrigin(origin)) return;

      // Handle context token request
      if (data?.type === "a3ai:request-context") {
        const token = await fetchToken();
        source.postMessage(
          { type: "a3ai:context", token: token || null },
          origin
        );
        // Also send site config
        source.postMessage(
          { type: "a3ai:site-config", siteUrl: siteUrl },
          origin
        );
      }

      // Handle login request (Loop 6)
      if (data?.type === "a3ai:login-request") {
        const result = await performLogin(data.username, data.password);
        source.postMessage(
          { type: "a3ai:login-result", ...result },
          origin
        );
      }

      // Handle page reload request (after inline login)
      if (data?.type === "a3ai:reload-page") {
        console.log("[DEF-BRIDGE] Page reload requested by chatbot");
        window.location.reload();
      }
    } catch (e) {
      // silent
    }
  });
})(); 


