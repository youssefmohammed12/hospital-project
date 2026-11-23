/*
 * HealthBridge Patient Workspace — Public V2 shell configuration.
 * All shell behaviour lives in core/shell-core.js (HealthBridgeShell);
 * this file only describes how the patient workspace differs: its
 * class prefix, role, route table and labels.
 * Depends on the Public V2 shared site.js runtime and shell-core.js.
 */
"use strict";

window.HealthBridgeShell.create({
  prefix: "patient",
  role: "patient",
  homePage: "dashboard.html",
  kicker: "PATIENT WORKSPACE",
  eyebrow: "YOUR HEALTH",
  brandLabel: "HealthBridge patient workspace home",
  sidebarLabel: "Patient workspace navigation",
  navLabel: "Patient pages",
  accountLabel: "Patient account",
  fallbackName: "Patient",
  fallbackInitials: "PT",
  sidebarKey: "hb_patient_sidebar",
  activeRouteKey: "hb_patient_active_route",
  links: [
    ["dashboard.html", "Dashboard", "dashboard"],
    ["appointments.html", "Appointments", "appointments"],
    ["history.html", "Medical History", "history"],
    ["prescriptions.html", "Prescriptions", "prescriptions"],
    ["notifications.html", "Notifications", "notifications"],
    ["downloads.html", "Document Center", "documents"],
    ["profile.html", "Profile", "profile"],
    // Health Insights retired in Phase 5: profile completeness moved to
    // the Dashboard home and the medication tracker to Prescriptions.
  ],
});
