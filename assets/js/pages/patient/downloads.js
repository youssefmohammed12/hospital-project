/**
 * HealthBridge — Patient Document Center Controller
 * Renders categorized downloadable documents: Prescriptions, Visit Summaries,
 * Confirmations, and disabled states for pending document categories.
 */
"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();

  const user = getUser();
  if (!user || user.role !== "patient") {
    window.location.href = getBasePath() + "pages/auth/login.html";
    return;
  }

  const container = document.getElementById("downloads-container");
  if (!container) return;

  const result = await apiFetch(
    getBasePath() + "api/patient/dashboard.php",
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ sections: ["downloads"] })
    },
    "Failed to load document center."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const data = result.data?.data;
    const dataRecord = data && typeof data === "object" && !Array.isArray(data)
      ? data
      : {};
    const rawDownloads = dataRecord.downloads;
    const downloads = rawDownloads && typeof rawDownloads === "object" && !Array.isArray(rawDownloads)
      ? rawDownloads
      : {};

    const normalizeDocuments = (collection) => {
      if (!Array.isArray(collection)) return [];
      return collection
        .filter((document) =>
          document &&
          typeof document === "object" &&
          !Array.isArray(document)
        )
        .slice(0, 50)
        .map((document) => ({
          label: typeof document.label === "string" && document.label.trim() !== ""
            ? document.label.trim().slice(0, 160)
            : "Document",
          doctor: typeof document.doctor === "string"
            ? document.doctor.trim().slice(0, 120)
            : "",
          date: typeof document.date === "string" &&
              document.date.trim().length <= 40 &&
              !Number.isNaN(Date.parse(document.date.trim()))
            ? document.date.trim()
            : "",
          url: typeof document.url === "string"
            ? document.url.trim().slice(0, 500)
            : "",
        }));
    };

    const prescriptions = normalizeDocuments(downloads.prescriptions);
    const confirmations = normalizeDocuments(downloads.confirmations);
    const visitSummaries = normalizeDocuments(downloads.visit_summaries);

    const appBasePath = new URL(getBasePath(), window.location.href).pathname;
    const apiPathPrefix = `${appBasePath.replace(/\/+$/, "")}/api/`;

    const getAllowedDownloadUrl = (value) => {
      if (typeof value !== "string" || !value.trim() || value.length > 500) return "";

      const candidateUrl = safeSameOriginUrl(getBasePath() + value.trim(), "");
      if (!candidateUrl || !candidateUrl.startsWith(apiPathPrefix)) return "";

      try {
        const parsedUrl = new URL(candidateUrl, window.location.origin);
        if (parsedUrl.hash) return "";

        const routePath = parsedUrl.pathname.slice(apiPathPrefix.length);
        const params = parsedUrl.searchParams;
        const idValues = params.getAll("id");
        if (idValues.length !== 1) return "";

        const id = safePositiveId(idValues[0]);
        if (!id || String(id) !== idValues[0]) return "";

        const keys = Array.from(params.keys());
        if (routePath === "prescriptions/print.php") {
          if (keys.some(key => key !== "id")) return "";
          return `${parsedUrl.pathname}?id=${id}`;
        }

        if (routePath === "appointments/print.php") {
          if (keys.some(key => key !== "id" && key !== "type")) return "";
          const types = params.getAll("type");
          if (types.length > 1 || (types.length === 1 && types[0] !== "summary")) return "";
          return `${parsedUrl.pathname}?id=${id}${types.length === 1 ? "&type=summary" : ""}`;
        }
      } catch {
        return "";
      }

      return "";
    };

    const renderDocCard = (title, icon, doctor, date, url, btnText = "Download PDF") => {
      const downloadUrl = getAllowedDownloadUrl(url);
      const action = downloadUrl
        ? `<a href="${escapeHTML(downloadUrl)}" class="btn btn-outline btn-sm" target="_blank" rel="noopener noreferrer"><i class="fas fa-download" aria-hidden="true"></i> ${escapeHTML(btnText)}</a>`
        : `<button class="btn btn-outline btn-sm" type="button" disabled><i class="fas fa-lock" aria-hidden="true"></i> Unavailable</button>`;

      return `
      <article class="card patient-download-card">
        <div class="patient-download-card__main">
          <div class="patient-download-card__icon">
            <i class="fas ${safeClassToken(icon, "fa-file-lines")}" aria-hidden="true"></i>
          </div>
          <div>
            <h4 class="patient-download-card__title">${escapeHTML(title)}</h4>
            <p class="patient-download-card__meta">
              ${doctor ? `Dr. ${escapeHTML(doctor)} • ` : ""}${date ? escapeHTML(formatDate(date)) : ""}
            </p>
          </div>
        </div>
        ${action}
      </article>`;
    };

    const renderDisabledCard = (title, icon, reason) => `
      <article class="card patient-download-card patient-download-card--disabled">
        <div class="patient-download-card__main">
          <div class="patient-download-card__icon patient-download-card__icon--disabled">
            <i class="fas ${safeClassToken(icon, "fa-file-lines")}" aria-hidden="true"></i>
          </div>
          <div>
            <h4 class="patient-download-card__title patient-download-card__title--disabled">${escapeHTML(title)}</h4>
            <p class="patient-download-card__meta patient-download-card__meta--disabled">${escapeHTML(reason)}</p>
          </div>
        </div>
        <button class="btn btn-outline btn-sm patient-download-card__unavailable" disabled>
          <i class="fas fa-lock" aria-hidden="true"></i> Unavailable
        </button>
      </article>`;

    container.innerHTML = `
      <!-- Prescriptions Section -->
      <div class="patient-download-section">
        <h3 class="patient-download-section__title">
          <i class="fas fa-prescription patient-download-section__title-icon" aria-hidden="true"></i> Prescriptions
        </h3>
        ${
          prescriptions.length > 0
            ? prescriptions.map(p => renderDocCard(p.label, "fa-prescription-bottle-medical", p.doctor, p.date, p.url, "Download PDF")).join("")
            : renderDisabledCard("Prescriptions Export", "fa-prescription-bottle-medical", "Available once your first prescription is issued.")
        }
      </div>

      <!-- Visit Summaries Section -->
      <div class="patient-download-section">
        <h3 class="patient-download-section__title">
          <i class="fas fa-notes-medical patient-download-section__title-icon" aria-hidden="true"></i> Visit Summaries & Clinical Notes
        </h3>
        ${
          visitSummaries.length > 0
            ? visitSummaries.map(v => renderDocCard(v.label, "fa-file-medical", v.doctor, v.date, v.url, "Download Summary")).join("")
            : renderDisabledCard("Clinical Visit Summary", "fa-file-medical", "Available once your doctor completes a consultation.")
        }
      </div>

      <!-- Appointment History & Confirmations Section -->
      <div class="patient-download-section">
        <h3 class="patient-download-section__title">
          <i class="fas fa-calendar-check patient-download-section__title-icon" aria-hidden="true"></i> Appointment Confirmations
        </h3>
        ${
          confirmations.length > 0
            ? confirmations.map(c => renderDocCard(c.label, "fa-file-circle-check", c.doctor, c.date, c.url, "Print Slip")).join("")
            : renderDisabledCard("Booking Confirmations", "fa-file-circle-check", "Available once your first appointment is confirmed.")
        }
      </div>

      <!-- Other Medical Records & Billing (Future & Disabled Cards) -->
      <div class="patient-download-section">
        <h3 class="patient-download-section__title">
          <i class="fas fa-vault patient-download-section__title-icon" aria-hidden="true"></i> Special Documents & Records
        </h3>
        ${renderDisabledCard("Lab Results & Diagnostic Reports", "fa-vial", "Available once lab results are uploaded by your provider.")}
        ${renderDisabledCard("Vaccination & Immunization Record", "fa-syringe", "Available once immunization history is filed.")}
        ${renderDisabledCard("Hospital Billing & Invoices", "fa-receipt", "Available once billing features are enabled by your hospital.")}
      </div>`;
  } else {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
        <h4>Unable to Load Document Center</h4>
        <p>Please try again later.</p>
        <button class="btn btn-outline btn-sm" data-page-action="retry">Retry</button>
      </div>`;
  }
});