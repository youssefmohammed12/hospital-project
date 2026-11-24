"use strict";

// Give the prescription layout a moment to settle before opening the print dialog.
window.addEventListener("load", () => {
  window.setTimeout(() => window.print(), 300);
});

// Close windows opened by the application after printing completes.
window.addEventListener("afterprint", () => {
  window.close();
});

// Fallback for browsers where afterprint is not reliably dispatched.
const printMedia = window.matchMedia("print");
const handlePrintMediaChange = (event) => {
  if (!event.matches) {
    window.setTimeout(() => window.close(), 200);
  }
};

if (typeof printMedia.addEventListener === "function") {
  printMedia.addEventListener("change", handlePrintMediaChange);
} else if (typeof printMedia.addListener === "function") {
  printMedia.addListener(handlePrintMediaChange);
}
