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
      body: JSON.stringify({})
    },
    "Failed to load document center."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    const data = result.data.data;
    const downloads = data?.downloads || {};
    const prescriptions = downloads.prescriptions || [];
    const confirmations = downloads.confirmations || [];
    const visitSummaries = downloads.visit_summaries || [];

    const renderDocCard = (title, icon, doctor, date, url, btnText = "Download PDF") => `
      <div class="card" style="padding:var(--s4) var(--s5);margin-bottom:var(--s3);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--s3);border:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:var(--s4)">
          <div style="width:44px;height:44px;border-radius:var(--r-md);background:var(--primary-subtle);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:1.2rem">
            <i class="fas ${icon}"></i>
          </div>
          <div>
            <h4 style="margin:0;font-size:0.95rem;color:var(--text-primary)">${escapeHTML(title)}</h4>
            <p style="margin:0;font-size:0.8rem;color:var(--text-secondary)">
              ${doctor ? `Dr. ${escapeHTML(doctor)} • ` : ""}${date ? formatDate(date) : ""}
            </p>
          </div>
        </div>
        <a href="${getBasePath()}${url}" class="btn btn-outline btn-sm" target="_blank">
          <i class="fas fa-download" aria-hidden="true"></i> ${btnText}
        </a>
      </div>`;

    const renderDisabledCard = (title, icon, reason) => `
      <div class="card" style="padding:var(--s4) var(--s5);margin-bottom:var(--s3);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--s3);border:1px dashed var(--border);opacity:0.7">
        <div style="display:flex;align-items:center;gap:var(--s4)">
          <div style="width:44px;height:44px;border-radius:var(--r-md);background:var(--bg-surface);display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:1.2rem">
            <i class="fas ${icon}"></i>
          </div>
          <div>
            <h4 style="margin:0;font-size:0.95rem;color:var(--text-secondary)">${escapeHTML(title)}</h4>
            <p style="margin:0;font-size:0.8rem;color:var(--text-muted)">${escapeHTML(reason)}</p>
          </div>
        </div>
        <button class="btn btn-outline btn-sm" disabled style="cursor:not-allowed">
          <i class="fas fa-lock" aria-hidden="true"></i> Unavailable
        </button>
      </div>`;

    container.innerHTML = `
      <!-- Prescriptions Section -->
      <div style="margin-bottom:var(--s6)">
        <h3 style="margin-bottom:var(--s4);font-size:1.1rem;display:flex;align-items:center;gap:var(--s2)">
          <i class="fas fa-prescription" style="color:var(--primary)"></i> Prescriptions
        </h3>
        ${
          prescriptions.length > 0
            ? prescriptions.map(p => renderDocCard(p.label, "fa-prescription-bottle-medical", p.doctor, p.date, p.url, "Download PDF")).join("")
            : renderDisabledCard("Prescriptions Export", "fa-prescription-bottle-medical", "Available once your first prescription is issued.")
        }
      </div>

      <!-- Visit Summaries Section -->
      <div style="margin-bottom:var(--s6)">
        <h3 style="margin-bottom:var(--s4);font-size:1.1rem;display:flex;align-items:center;gap:var(--s2)">
          <i class="fas fa-notes-medical" style="color:var(--primary)"></i> Visit Summaries & Clinical Notes
        </h3>
        ${
          visitSummaries.length > 0
            ? visitSummaries.map(v => renderDocCard(v.label, "fa-file-medical", v.doctor, v.date, v.url, "Download Summary")).join("")
            : renderDisabledCard("Clinical Visit Summary", "fa-file-medical", "Available once your doctor completes a consultation.")
        }
      </div>

      <!-- Appointment History & Confirmations Section -->
      <div style="margin-bottom:var(--s6)">
        <h3 style="margin-bottom:var(--s4);font-size:1.1rem;display:flex;align-items:center;gap:var(--s2)">
          <i class="fas fa-calendar-check" style="color:var(--primary)"></i> Appointment Confirmations
        </h3>
        ${
          confirmations.length > 0
            ? confirmations.map(c => renderDocCard(c.label, "fa-file-circle-check", c.doctor, c.date, c.url, "Print Slip")).join("")
            : renderDisabledCard("Booking Confirmations", "fa-file-circle-check", "Available once your first appointment is confirmed.")
        }
      </div>

      <!-- Other Medical Records & Billing (Future & Disabled Cards) -->
      <div style="margin-bottom:var(--s6)">
        <h3 style="margin-bottom:var(--s4);font-size:1.1rem;display:flex;align-items:center;gap:var(--s2)">
          <i class="fas fa-vault" style="color:var(--primary)"></i> Special Documents & Records
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
        <button class="btn btn-outline btn-sm" onclick="location.reload()">Retry</button>
      </div>`;
  }
});