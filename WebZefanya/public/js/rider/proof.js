// Fallback rider proof-of-delivery draft preservation.
// This file is provided as a static asset fallback only. The proof page
// prefers the Vite-built public/build assets when the manifest is available;
// this file is a no-op placeholder kept for environments where that manifest
// is not published.
(function () {
  if (typeof window !== 'undefined') {
    window.__riderPodProof = window.__riderPodProof || {};
  }
})();
