/**
 * HealthBridge — Patient Prescriptions Page Controller
 *
 * Full prescription management: doctor details, medication details,
 * dosage/frequency instructions, search & status filtering, print & PDF export.
 */
"use strict";

document.addEventListener("DOMContentLoaded", async () => {
  requireAuth();

  const user = getUser();
  if (!user || user.role !== "patient") {
    window.location.href = getBasePath() + "pages/auth/login.html";
    return;
  }

  const container = document.getElementById("prescriptions-container");
  if (!container) return;

  let allPrescriptions = [];
  let currentFilter = "all";
  let searchQuery = "";

  // 1. Fetch prescriptions from API
  const result = await apiFetch(
    getBasePath() + "api/prescriptions/get.php",
    {},
    "Failed to load prescriptions."
  );

  container.innerHTML = "";

  if (result.ok && result.data?.success) {
    allPrescriptions = result.data.prescriptions || [];
    render();
  } else {
    // Fallback: use dashboard API if prescriptions API returns empty
    const dashResult = await apiFetch(
      getBasePath() + "api/patient/dashboard.php",
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({})
      },
      "Failed to load prescriptions."
    );

    if (dashResult.ok && dashResult.data?.success) {
      allPrescriptions = dashResult.data.data?.prescriptions?.prescriptions || [];
      render();
    } else {
      showError();
    }
  }

  // 2. Event Listeners for Filter Chips and Search Input
  const searchInput = document.getElementById("rx-search-input");
  if (searchInput) {
    searchInput.addEventListener("input", (e) => {
      searchQuery = e.target.value.trim().toLowerCase();
      render();
    });
  }

  const filterChips = document.querySelectorAll("#rx-filter-chips .filter-chip");
  filterChips.forEach((chip) => {
    chip.addEventListener("click", () => {
      filterChips.forEach((c) => c.classList.remove("active"));
      chip.classList.add("active");
      currentFilter = chip.getAttribute("data-filter") || "all";
      render();
    });
  });

  // 3. Render Function
  function render() {
    let filtered = allPrescriptions.slice();

    // Filter by status
    if (currentFilter !== "all") {
      filtered = filtered.filter(
        (rx) => (rx.status || "").toLowerCase() === currentFilter.toLowerCase()
      );
    }

    // Filter by search query
    if (searchQuery) {
      filtered = filtered.filter((rx) => {
        const docName = (rx.doctor_name || "").toLowerCase();
        const dept = (rx.department || rx.appt_department || "").toLowerCase();
        const itemsMatch = (rx.items || []).some(
          (item) =>
            (item.medication_name || "").toLowerCase().includes(searchQuery) ||
            (item.instructions || "").toLowerCase().includes(searchQuery)
        );
        return docName.includes(searchQuery) || dept.includes(searchQuery) || itemsMatch;
      });
    }

    if (filtered.length === 0) {
      if (allPrescriptions.length === 0) {
        container.innerHTML = `
          <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-prescription-bottle" aria-hidden="true"></i></div>
            <h4>No Prescriptions Yet</h4>
            <p>Your doctors will publish prescriptions here after your consultations.</p>
            <a href="dashboard.html" class="btn btn-primary btn-sm">Back to Dashboard</a>
          </div>`;
      } else {
        container.innerHTML = `
          <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-search" aria-hidden="true"></i></div>
            <h4>No Matching Prescriptions</h4>
            <p>Try adjusting your search query or status filter.</p>
          </div>`;
      }
      return;
    }

    container.innerHTML = filtered
      .map((rx) => {
        const items = rx.items || [];
        const status = rx.status || "Active";
        const statusClass =
          status === "Active"
            ? "rx-status-badge rx-status-active"
            : status === "Completed"
            ? "rx-status-badge rx-status-completed"
            : "rx-status-badge rx-status-cancelled";
        const docInitials = (rx.doctor_name || "Doctor")
          .split(" ")
          .map((n) => n[0])
          .join("")
          .substring(0, 2)
          .toUpperCase();

        return `
        <div class="card prescription-card" style="margin-bottom:var(--s5);padding:var(--s6);border:1px solid var(--border)">
          
          <!-- Header: Doctor info + status badge -->
          <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:var(--s3);margin-bottom:var(--s4);padding-bottom:var(--s4);border-bottom:1px solid var(--border-light)">
            <div style="display:flex;align-items:center;gap:var(--s4)">
              <div style="width:52px;height:52px;border-radius:50%;background:var(--primary-subtle);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--primary);font-size:1.1rem">
                ${docInitials}
              </div>
              <div>
                <h3 style="margin:0;font-size:1.1rem;color:var(--text-primary)">${escapeHTML(rx.doctor_name || "Doctor")}</h3>
                <p style="margin:0;font-size:0.85rem;color:var(--text-secondary)">
                  <i class="fas fa-stethoscope" style="color:var(--primary)"></i> ${escapeHTML(rx.department || rx.appt_department || "General Medical")}
                  ${rx.doctor_specialty ? ` • ${escapeHTML(rx.doctor_specialty)}` : ""}
                </p>
                <div style="font-size:0.78rem;color:var(--text-muted);margin-top:var(--s1)">
                  <i class="fas fa-calendar-alt"></i> Issued ${formatDate(rx.created_at || rx.appointment_date)}
                </div>
              </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:var(--s2)">
              <span class="${statusClass}" style="padding:4px 12px;border-radius:var(--r-full);font-size:0.8rem;font-weight:600">${escapeHTML(status)}</span>
              <span style="font-size:0.75rem;color:var(--text-muted)">${items.length} Medication${items.length !== 1 ? "s" : ""}</span>
            </div>
          </div>

          <!-- Body: Medications List -->
          <div style="margin-bottom:var(--s5)">
            <h4 style="font-size:0.85rem;color:var(--text-muted);margin-bottom:var(--s3);text-transform:uppercase;letter-spacing:0.5px">
              <i class="fas fa-pills" style="color:var(--primary)"></i> Prescribed Medications
            </h4>
            <div style="display:flex;flex-direction:column;gap:var(--s3)">
              ${items
                .map(
                  (item) => `
                <div style="background:var(--bg-surface);border:1px solid var(--border-light);border-radius:var(--r-md);padding:var(--s4);display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:var(--s3)">
                  <div style="flex:1;min-width:200px">
                    <div style="font-weight:700;font-size:0.95rem;color:var(--text-primary)">
                      ${escapeHTML(item.medication_name || "")} ${item.strength ? `<span style="font-weight:400;color:var(--primary)">(${escapeHTML(item.strength)})</span>` : ""}
                    </div>
                    <div style="font-size:0.83rem;color:var(--text-secondary);margin-top:var(--s1)">
                      <i class="fas fa-clock" style="color:var(--text-muted)"></i> ${escapeHTML(item.dosage || "")} • ${escapeHTML(item.frequency || "")}
                      ${item.duration ? ` • Duration: ${escapeHTML(item.duration)}` : ""}
                    </div>
                    ${
                      item.instructions
                        ? `<div style="font-size:0.8rem;color:var(--text-muted);margin-top:var(--s2);font-style:italic;background:var(--bg-card);padding:var(--s2) var(--s3);border-radius:var(--r-sm)">
                            <i class="fas fa-info-circle" style="color:var(--info)"></i> Instructions: ${escapeHTML(item.instructions)}
                           </div>`
                        : ""
                    }
                  </div>
                </div>`
                )
                .join("")}
            </div>
          </div>

          ${
            rx.notes
              ? `<div style="margin-bottom:var(--s4);padding:var(--s3) var(--s4);background:var(--bg-surface);border-left:3px solid var(--primary);border-radius:0 var(--r-sm) var(--r-sm) 0;font-size:0.85rem;color:var(--text-secondary)">
                  <strong>Doctor's Note:</strong> ${escapeHTML(rx.notes)}
                 </div>`
              : ""
          }

          <!-- Footer: Actions -->
          <div style="display:flex;gap:var(--s3);justify-content:flex-end;padding-top:var(--s3);border-top:1px solid var(--border-light)">
            <button class="btn btn-outline btn-sm" onclick="window.open('${getBasePath()}api/prescriptions/print.php?id=${rx.id}', '_blank')">
              <i class="fas fa-print" aria-hidden="true"></i> Print Prescription
            </button>
            <button class="btn btn-primary btn-sm" onclick="window.open('${getBasePath()}api/prescriptions/print.php?id=${rx.id}', '_blank')">
              <i class="fas fa-file-pdf" aria-hidden="true"></i> Download PDF
            </button>
          </div>
        </div>`;
      })
      .join("");
  }

  function showError() {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></div>
        <h4>Unable to Load Prescriptions</h4>
        <p>Please try refreshing the page.</p>
        <button class="btn btn-outline btn-sm" onclick="location.reload()">Retry</button>
      </div>`;
  }
});