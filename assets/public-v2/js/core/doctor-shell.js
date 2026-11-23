/*
 * HealthBridge Doctor Workspace — Public V2 shell configuration.
 * All shell behaviour lives in core/shell-core.js (HealthBridgeShell);
 * this file only describes how the doctor workspace differs: its
 * class prefix, role, route table and labels.
 * Depends on the Public V2 shared site.js runtime and shell-core.js.
 */
"use strict";

window.HealthBridgeShell.create({
  prefix: "doctor",
  role: "doctor",
  homePage: "doctor-dashboard.html",
  kicker: "DOCTOR WORKSPACE",
  eyebrow: "PATIENT CARE",
  brandLabel: "HealthBridge doctor workspace home",
  sidebarLabel: "Doctor workspace navigation",
  navLabel: "Doctor pages",
  accountLabel: "Doctor account",
  fallbackName: "Doctor",
  fallbackInitials: "DR",
  sidebarKey: "hb_doctor_sidebar",
  activeRouteKey: "hb_doctor_active_route",
  links: [
    ["doctor-dashboard.html", "Overview", "dashboard"],
    ["queue.html", "Patient Queue", "queue"],
    ["appointments.html", "Appointments", "appointments"],
    ["schedule.html", "Schedule", "schedule"],
    ["reviews.html", "Reviews", "reviews"],
    ["notifications.html", "Notifications", "notifications"],
    ["profile.html", "Profile", "profile"],
  ],
});
