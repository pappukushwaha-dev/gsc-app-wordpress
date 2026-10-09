
'use strict';

// sidebar submenu collapsible js
document.querySelectorAll(".sidebar-menu .dropdown").forEach(function (dropdown) {
  dropdown.addEventListener("click", function () {
    var item = this;

    // Close all sibling dropdowns
    item.parentNode.querySelectorAll(".dropdown").forEach(function (sibling) {
      if (sibling !== item) {
        sibling.querySelector(".sidebar-submenu").style.display = 'none';
        sibling.classList.remove("dropdown-open");
        sibling.classList.remove("open");
      }
    });

    // Toggle the current dropdown
    var submenu = item.querySelector(".sidebar-submenu");
    submenu.style.display = (submenu.style.display === 'block') ? 'none' : 'block';

    item.classList.toggle("dropdown-open");
  });
});

// Toggle sidebar visibility and active class
const sidebarToggle = document.querySelector(".sidebar-toggle");
if (sidebarToggle) {
  sidebarToggle.addEventListener("click", function () {
    this.classList.toggle("active");
    document.querySelector(".sidebar").classList.toggle("active");
    document.querySelector(".dashboard-main").classList.toggle("active");
  });
}

// Open sidebar in mobile view and add overlay
const sidebarMobileToggle = document.querySelector(".sidebar-mobile-toggle");
if (sidebarMobileToggle) {
  sidebarMobileToggle.addEventListener("click", function () {
    document.querySelector(".sidebar").classList.add("sidebar-open");
    document.body.classList.add("overlay-active");
  });
}

// Close sidebar and remove overlay
const sidebarColseBtn = document.querySelector(".sidebar-close-btn");
if (sidebarColseBtn) {
  sidebarColseBtn.addEventListener("click", function () {
    document.querySelector(".sidebar").classList.remove("sidebar-open");
    document.body.classList.remove("overlay-active");
  });
}

//to keep the current page active
document.addEventListener("DOMContentLoaded", function () {
  var nk = window.location.href;
  var links = document.querySelectorAll("ul#sidebar-menu a");

  links.forEach(function (link) {
    if (link.href === nk) {
      link.classList.add("active-page"); // anchor
      var parent = link.parentElement;
      parent.classList.add("active-page"); // li

      // Traverse up the DOM tree and add classes to parent elements
      while (parent && parent.tagName !== "BODY") {
        if (parent.tagName === "LI") {
          parent.classList.add("show");
          parent.classList.add("open");
        }
        parent = parent.parentElement;
      }
    }
  });
});




// On page load or when changing themes, best to add inline in `head` to avoid FOUC
if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
  document.documentElement.classList.add('dark');
} else {
  document.documentElement.classList.remove('dark')
}

// light dark version js
var themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');
var themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');

// Change the icons inside the button based on previous settings
if (themeToggleDarkIcon || themeToggleLightIcon) {
  if (localStorage.getItem('color-theme') === 'dark' || (!('color-theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    themeToggleLightIcon.classList.remove('hidden');
  } else {
    themeToggleDarkIcon.classList.remove('hidden');
  }
}

var themeToggleBtn = document.getElementById('theme-toggle');

if (themeToggleDarkIcon || themeToggleLightIcon || themeToggleBtn) {
  themeToggleBtn.addEventListener('click', function () {

    // toggle icons inside button
    themeToggleDarkIcon.classList.toggle('hidden');
    themeToggleLightIcon.classList.toggle('hidden');

    // if set via local storage previously
    if (localStorage.getItem('color-theme')) {
      if (localStorage.getItem('color-theme') === 'light') {
        document.documentElement.classList.add('dark');
        localStorage.setItem('color-theme', 'dark');
      } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('color-theme', 'light');
      }

      // if NOT set via local storage previously
    } else {
      if (document.documentElement.classList.contains('dark')) {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('color-theme', 'light');
      } else {
        document.documentElement.classList.add('dark');
        localStorage.setItem('color-theme', 'dark');
      }
    }
  });
}







// Alert Toast Logic - Start ====================================================================
function showAlert(message, type = "success") {
  const container = document.getElementById("alert-container");

  const typeClasses = {
    success: {
      wrapper: "bg-success-100 text-success-600 border-l-4 border-success-600",
      icon: "akar-icons:double-check",
      textColor: "dark:text-success-600"
    },
    error: {
      wrapper: "bg-danger-100 text-danger-600 border-l-4 border-danger-600",
      icon: "mdi:alert-circle-outline",
      textColor: "dark:text-danger-600"
    },
    warning: {
      wrapper: "bg-warning-100 text-warning-600 border-l-4 border-warning-600",
      icon: "mdi:alert-outline",
      textColor: "dark:text-warning-600"
    },
    info: {
      wrapper: "bg-info-100 text-info-600 border-l-4 border-info-600",
      icon: "mdi:information-outline",
      textColor: "dark:text-info-600"
    },
  };

  const { wrapper, icon, textColor } = typeClasses[type] || typeClasses.info;

  // Toast element (start hidden for animation)
  const toast = document.createElement("div");
  toast.className = `alert ${wrapper} px-6 py-3 rounded-md shadow-md flex items-center justify-between gap-3 toast-hidden`;
  toast.setAttribute("role", "alert");

  toast.innerHTML = `
    <div class="flex items-center gap-2">
      <iconify-icon icon="${icon}" class="text-xl"></iconify-icon>
      <span class="font-semibold ${textColor}">${message}</span>
    </div>
    <button class="remove-button">
      <iconify-icon icon="iconamoon:sign-times-light" class="text-xl"></iconify-icon>
    </button>
  `;

  // Close button
  toast.querySelector(".remove-button").addEventListener("click", () => {
    hideToast(toast);
  });

  // Append toast
  container.appendChild(toast);

  // 🔑 Force browser to recognize initial state before transition
  requestAnimationFrame(() => {
    // trigger reflow by reading property
    toast.offsetHeight; 
    toast.classList.remove("toast-hidden");
    toast.classList.add("toast-show");
  });

  // Auto-remove after 3s
  setTimeout(() => {
    hideToast(toast);
  }, 3000);
}

function hideToast(toast) {
  toast.classList.remove("toast-show");
  toast.classList.add("toast-hide");
  setTimeout(() => toast.remove(), 300); // matches CSS transition
}
// Alert Toast Logic - End ====================================================================