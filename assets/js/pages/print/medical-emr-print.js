"use strict";

// Print the prepared EMR once the document and its styles are loaded.
window.addEventListener("load", () => {
  window.print();
  window.setTimeout(() => window.close(), 500);
});
