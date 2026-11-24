/*
 * HealthBridge Admin Workspace — Public V2 shell configuration.
 * All shell behaviour lives in core/shell-core.js (HealthBridgeShell);
 * this file only describes how the admin workspace differs: its class
 * prefix, role, route table and labels.
 * Depends on the Public V2 shared site.js runtime and shell-core.js.
 */
"use strict";

window.HealthBridgeShell.create({
  prefix: "admin",
  role: "admin",
  homePage: "admin-dashboard.html",
  kicker: "ADMIN WORKSPACE",
  eyebrow: "OPERATIONS",
  brandLabel: "HealthBridge admin workspace home",
  sidebarLabel: "Admin workspace navigation",
  navLabel: "Admin pages",
  accountLabel: "Admin account",
  fallbackName: "Admin",
  fallbackInitials: "AD",
  sidebarKey: "hb_admin_sidebar",
  activeRouteKey: "hb_admin_active_route",
  // Decision D4 (admin plan): no bell in the admin shell — nothing in
  // the system produces notifications for the admin, so the header does
  // not ship a permanently empty one.
  showBell: false,
  // Decision D7 (admin plan): the admin has no separate Profile page —
  // the account record lives in Settings, so both account-menu links
  // point there.
  profileHref: "settings.html",
  settingsHref: "settings.html",
  links: [
    ["admin-dashboard.html", "Overview", "dashboard"],
    ["appointments.html", "Appointments", "appointments"],
    ["doctors.html", "Doctors", "doctors"],
    ["patients.html", "Patients", "patients"],
    ["prescriptions.html", "Prescriptions", "prescriptions"],
    ["departments.html", "Departments", "departments"],
    ["support-messages.html", "Support Messages", "support"],
    ["audit-log.html", "Audit Log", "history"],
    ["system-settings.html", "System Settings", "settings"],
    // The Doctor Profile is a child page of Doctors (admin plan D2),
    // reached from the doctors list — not a sidebar item. Own-account
    // Settings lives in the sidebar footer, like the sibling shells.
  ],
});
