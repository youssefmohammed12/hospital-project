/*
 * HealthBridge — shared public footer loader.
 * One trusted HTML fragment is rendered by every page that opts into the footer.
 */
(() => {
  "use strict";

  const FOOTER_SCRIPT_SUFFIX = "/assets/public-v2/js/core/footer.js";
  const FOOTER_SCRIPT_URL = document.currentScript?.src || "";

  function resolveSiteRoot() {
    if (!FOOTER_SCRIPT_URL) throw new Error("Shared footer script URL is unavailable.");

    const scriptUrl = new URL(FOOTER_SCRIPT_URL, document.baseURI);
    const marker = scriptUrl.pathname.lastIndexOf(FOOTER_SCRIPT_SUFFIX);
    if (marker < 0) throw new Error("Shared footer script path is unexpected.");

    const rootPath = scriptUrl.pathname.slice(0, marker + 1);
    return new URL(rootPath, scriptUrl.origin);
  }

  async function readTrustedFooter(response, requestedUrl) {
    if (!response?.ok) throw new Error("Shared footer request failed.");
    if (response.redirected) throw new Error("Redirected footer fragments are not accepted.");

    const contentType = response.headers?.get("content-type") || "";
    if (!/^text\/html(?:\s*;|$)/i.test(contentType.trim())) {
      throw new Error("Shared footer response must be HTML.");
    }

    const expectedUrl = new URL(requestedUrl.href);
    const finalUrl = new URL(response.url, document.baseURI);
    if (
      finalUrl.origin !== expectedUrl.origin ||
      finalUrl.pathname !== expectedUrl.pathname ||
      finalUrl.search !== expectedUrl.search
    ) {
      throw new Error("Unexpected shared footer response URL.");
    }

    return response.text();
  }

  function resolveFooterLinks(footer, siteRoot) {
    footer.querySelectorAll("a[data-footer-href]").forEach((link) => {
      const path = link.getAttribute("data-footer-href");
      if (!path) throw new Error("A shared footer link is missing its destination.");

      const target = new URL(path, siteRoot);
      if (target.origin !== siteRoot.origin || !target.pathname.startsWith(siteRoot.pathname)) {
        throw new Error("A shared footer link points outside the application.");
      }

      link.href = target.href;
      link.removeAttribute("data-footer-href");
    });
  }

  async function renderFooter(host, siteRoot) {
    const fragmentUrl = new URL("includes/fragments/footer.html?v=4", siteRoot);
    const response = await fetch(fragmentUrl.href, {
      credentials: "same-origin",
      headers: { Accept: "text/html" },
    });
    const markup = await readTrustedFooter(response, fragmentUrl);

    const template = document.createElement("template");
    template.innerHTML = markup.trim();

    if (
      template.content.children.length !== 1 ||
      !template.content.firstElementChild?.matches("footer.site-footer")
    ) {
      throw new Error("The shared footer fragment has an invalid root structure.");
    }

    const footer = template.content.firstElementChild;
    resolveFooterLinks(footer, siteRoot);
    host.replaceWith(template.content);
  }

  function initSharedFooter() {
    const hosts = document.querySelectorAll("[data-shared-footer]");
    if (!hosts.length) return;

    let siteRoot;
    try {
      siteRoot = resolveSiteRoot();
    } catch (error) {
      console.error("Shared footer initialization failed:", error);
      return;
    }

    hosts.forEach((host) => {
      if (host.dataset.footerLoading === "true") return;
      host.dataset.footerLoading = "true";
      renderFooter(host, siteRoot).catch((error) => {
        host.removeAttribute("aria-busy");
        host.dataset.footerError = "true";
        console.error("Shared footer could not be loaded:", error);
      });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initSharedFooter, { once: true });
  } else {
    initSharedFooter();
  }
})();
