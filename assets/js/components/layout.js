/**
 * HealthBridge — Layout Injector Component
 *
 * Dynamically loads shared layout fragments (navbar, sidebar) into the DOM.
 * Manages active state for sidebar navigation based on current URL.
 * Handles user authentication state in navbar.
 *
 * Usage:
 *   <script src="../../assets/js/components/layout.js"></script>
 *   <script>
 *     Layout.init().then(() => {
 *       // Layout loaded, initialize page-specific logic
 *     });
 *   </script>
 */

const Layout = {
  /** @type {Object|null} Current user data */
  user: null,

  /** @type {string} Base path for fragment loading */
  basePath: '',

  /**
   * Initialize the layout system
   * @returns {Promise<void>}
   */
  async init() {
    // Determine base path from current script location
    this.basePath = this.getBasePath();

    // Load user data first (for navbar auth state)
    this.user = this.getUser();

    // Load fragments in parallel
    await Promise.all([
      this.loadNavbar(),
      this.loadSidebar()
    ]);

    // Initialize navigation state
    this.setActiveNavigation();

    // Update sidebar user info
    this.updateSidebarUser();
  },

  /**
   * Get the base path for loading fragments
   * @returns {string}
   */
  getBasePath() {
    const script = document.querySelector('script[src*="layout.js"]');
    if (script) {
      const src = script.getAttribute('src');
      return src.substring(0, src.lastIndexOf('/assets/js/components/')) + '/';
    }

    const path = window.location.pathname;
    if (
      path.indexOf('/pages/admin/') !== -1 ||
      path.indexOf('/pages/doctor/') !== -1 ||
      path.indexOf('/pages/patient/') !== -1 ||
      path.indexOf('/pages/auth/') !== -1
    ) {
      return '../../../';
    }
    return '../../';
  },

  /**
   * Get current user from localStorage
   * @returns {Object|null}
   */
  getUser() {
    try {
      const userStr = localStorage.getItem('hb_user');
      return userStr ? JSON.parse(userStr) : null;
    } catch (e) {
      console.error('Failed to parse user data:', e);
      return null;
    }
  },

  /**
   * Load navbar fragment
   * @returns {Promise<void>}
   */
  async loadNavbar() {
    const navbarContainer = document.getElementById('navbar-container');
    if (!navbarContainer) {
      console.warn('Navbar container not found');
      return;
    }

    try {
      const response = await fetch(`${this.basePath}includes/fragments/navbar.html`);
      if (!response.ok) throw new Error('Failed to load navbar');

      const html = await response.text();
      navbarContainer.innerHTML = html;

      // Update auth links based on user state
      this.updateNavbarAuth();

      // Re-run main.js navbar initialization to inject theme toggle, hamburger, scroll effect
      // (initNavbar() runs on DOMContentLoaded but the navbar fragment wasn't loaded yet,
      //  so it returned early. We need to call it again now that the DOM exists.)
      if (typeof initNavbar === 'function') {
        initNavbar();
      }
      if (typeof initTheme === 'function') {
        initTheme();
      }
    } catch (error) {
      console.error('Error loading navbar:', error);
      navbarContainer.innerHTML = '<div class="navbar-error">Failed to load navigation</div>';
    }
  },

  /**
   * Load sidebar fragment
   * @returns {Promise<void>}
   */
  async loadSidebar() {
    const sidebarContainer = document.getElementById('sidebar-container');
    if (!sidebarContainer) {
      console.warn('Sidebar container not found');
      return;
    }

    try {
      const response = await fetch(`${this.basePath}includes/fragments/sidebar.html`);
      if (!response.ok) throw new Error('Failed to load sidebar');

      const html = await response.text();
      sidebarContainer.innerHTML = html;
    } catch (error) {
      console.error('Error loading sidebar:', error);
      sidebarContainer.innerHTML = '<div class="sidebar-error">Failed to load sidebar</div>';
    }
  },

  /**
   * Update navbar authentication links based on user state.
   * Restores the full navbar: notification bell, settings, user avatar, dashboard link, logout.
   * Matches the original styling from main.js's initNavbar().
   */
  updateNavbarAuth() {
    const authLinks = document.getElementById('auth-links');
    if (!authLinks) return;

    if (this.user) {
      // Full navbar matching main.js: bell, settings, name, dashboard link, logout button
      const user = this.user;
      const dashboardUrl = this.getDashboardUrl(user.role);

      authLinks.innerHTML = `
        <a href="${this.basePath}pages/patient/settings.html" class="nav-icon-btn" title="Settings" aria-label="Settings">
          <i class="fas fa-cog"></i>
        </a>
        <button class="nav-icon-btn" id="notif-bell-btn" title="Notifications" aria-label="Notifications">
          <i class="fas fa-bell"></i>
          <span class="notification-badge" id="notif-badge" style="display:none">0</span>
        </button>
        <span class="nav-user-name">${this.escapeHTML(user.name)}</span>
        <a href="${dashboardUrl}" class="nav-dashboard-link" title="Dashboard" aria-label="Open dashboard">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.8 12 3l9 7.8v9.7a.5.5 0 0 1-.5.5h-5.2a.5.5 0 0 1-.5-.5v-5.4H9.2v5.4a.5.5 0 0 1-.5.5H3.5a.5.5 0 0 1-.5-.5v-9.7Z"></path></svg>
          <span>Dashboard</span>
        </a>
        <button class="btn btn-outline btn-sm" onclick="Layout.logout()">Logout</button>
      `;

      // Initialize notification service for the bell
      if (typeof NotificationService !== 'undefined' && !window._hbNavbarNotifInit) {
        window._hbNavbarNotifInit = true;
        try {
          NotificationService.init();
          NotificationService.initDropdown();
        } catch(e) {
          console.warn('Notification init skipped:', e);
        }
      }
    } else {
      // User is not logged in
      authLinks.innerHTML = `
        <a href="${this.basePath}pages/auth/login.html" class="btn btn-outline btn-sm">Login</a>
        <a href="${this.basePath}pages/auth/login.html#signup" class="btn btn-primary btn-sm">Sign Up</a>
      `;
    }
  },

  /**
   * Get the correct dashboard URL for a user role
   */
  getDashboardUrl(role) {
    if (role === 'admin') return this.basePath + 'pages/admin/admin.html';
    if (role === 'doctor') return this.basePath + 'pages/doctor/doctor-dashboard.html';
    return this.basePath + 'pages/patient/dashboard.html';
  },

  /**
   * Update sidebar user information
   */
  updateSidebarUser() {
    const nameEl = document.getElementById('sidebar-name');
    const emailEl = document.getElementById('sidebar-email');
    const avatar = document.querySelector('.sidebar-avatar');

    if (this.user) {
      if (nameEl) nameEl.textContent = this.user.name || 'Patient';
      if (emailEl) emailEl.textContent = this.user.email || '';

      if (avatar && this.user.name) {
        const initials = this.user.name
          .split(' ')
          .map(n => n[0])
          .join('')
          .substring(0, 2)
          .toUpperCase();
        avatar.textContent = initials || '??';
      }
    }
  },

  /**
   * Set active state for navigation links based on current URL
   */
  setActiveNavigation() {
    const currentPage = window.location.pathname.split('/').pop();

    // Set active class for sidebar links
    document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
      const href = link.getAttribute('href');
      if (href === currentPage || (currentPage === '' && href === 'dashboard.html')) {
        link.classList.add('active');
      } else {
        link.classList.remove('active');
      }
    });

    // Set active class for navbar links
    document.querySelectorAll('.navbar .nav-links a').forEach(link => {
      const href = link.getAttribute('href');
      if (window.location.pathname.endsWith(href)) {
        link.classList.add('active');
      } else {
        link.classList.remove('active');
      }
    });
  },

  /**
   * Escape HTML to prevent XSS
   * @param {string} str
   * @returns {string}
   */
  escapeHTML(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  },

  /**
   * Handle logout
   */
  logout() {
    localStorage.removeItem('hb_user');
    localStorage.removeItem('hb_token');
    window.location.href = this.basePath + 'pages/auth/login.html';
  }
};

// Expose logout function globally for onclick handlers
window.logoutUser = () => Layout.logout();

// Auto-initialize if DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => Layout.init());
} else {
  Layout.init();
}
