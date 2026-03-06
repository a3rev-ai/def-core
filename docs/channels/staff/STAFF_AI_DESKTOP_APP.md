STAFF_AI_DESKTOP_APP.md

Path:
/docs/channels/staff/STAFF_AI_DESKTOP_APP.md

# Digital Employee Framework
## Staff AI — Desktop App Installation Guide

**Feature:** Staff AI Progressive Web App (PWA)
**Audience:** Staff, management, and administrators
**Browsers supported:** Google Chrome, Microsoft Edge, Mozilla Firefox

---

## 1. What Is the Staff AI Desktop App?

Staff AI can be installed as a **desktop application** directly from your web browser. Once installed, it:

- Creates a shortcut on your desktop (and optionally your taskbar/dock)
- Opens in its own standalone window — no browser address bar or tabs
- Launches instantly, just like any other desktop application
- Automatically connects to your company's Staff AI instance

This is not a separate download. It uses your browser's built-in Progressive Web App (PWA) technology to create a lightweight app wrapper around Staff AI.

---

## 2. Installing the Desktop App

### Google Chrome

**Method 1 — Install button in Staff AI header:**
1. Navigate to your Staff AI URL (e.g., `https://yoursite.com/staff-ai/`)
2. Log in if prompted
3. Look for the **Install** button in the Staff AI header bar (between Share and the external link icon)
4. Click **Install** — Chrome will show a confirmation dialog
5. Click **Install** in the dialog
6. The app will open in a standalone window and a shortcut will appear on your desktop

**Method 2 — Browser install prompt:**
1. Navigate to your Staff AI URL
2. Look for the **install icon** in Chrome's address bar (a monitor with a down arrow)
3. Click it and confirm the installation

**Method 3 — Chrome menu:**
1. Navigate to your Staff AI URL
2. Click the **three-dot menu** (top-right of Chrome)
3. Look for **"Install Staff AI"** or **"Save and share" > "Install Staff AI"**
4. Confirm the installation

### Microsoft Edge

**Method 1 — Install button in Staff AI header:**
1. Navigate to your Staff AI URL
2. Log in if prompted
3. Click the **Install** button in the Staff AI header bar
4. Edge will show a confirmation dialog — click **Install**
5. A desktop shortcut is created automatically

**Method 2 — Edge address bar:**
1. Navigate to your Staff AI URL
2. Look for the **app available icon** in Edge's address bar (a monitor with a plus sign)
3. Click it and confirm

**Method 3 — Edge menu:**
1. Navigate to your Staff AI URL
2. Click the **three-dot menu** (top-right of Edge)
3. Select **"Apps" > "Install Staff AI"**
4. Confirm the installation

### Mozilla Firefox

Firefox has limited PWA support on desktop. The recommended approach:

1. Navigate to your Staff AI URL
2. **Bookmark the page** — click the star icon in the address bar
3. Drag the bookmark to your desktop to create a shortcut
4. Firefox will open Staff AI in a regular browser tab when you click the shortcut

> **Note:** Firefox does not support installing PWAs as standalone desktop apps on Windows or macOS. For the best desktop app experience, use Chrome or Edge.

---

## 3. Using the Desktop App

Once installed, the Staff AI desktop app works exactly like the browser version:

- **All features available** — chat, file uploads, export, share, create, theme toggle
- **Stays logged in** — your session persists between launches (same as browser)
- **Automatic updates** — the app always loads the latest version from your server
- **Keyboard shortcut** — you can pin the app to your taskbar (Windows) or dock (macOS) for quick access

### Opening the App

- **Desktop shortcut** — double-click the Staff AI icon on your desktop
- **Taskbar/dock** — right-click the app while it's open and choose "Pin to taskbar" (Windows) or "Options > Keep in Dock" (macOS)
- **Start menu** — search for "Staff AI" in your system's app launcher

### Session and Login

- If your session has expired, the app will redirect you to the login page
- After logging in, you'll land directly back in Staff AI
- Your conversation history is preserved across sessions

---

## 4. Setting the App Icon (Administrators)

Administrators can customize the icon that appears on users' desktops.

### Uploading a Custom Icon

1. Go to **wp-admin > Digital Employees > Branding**
2. Scroll down to the **Web App Icon** section
3. Click **Select Icon**
4. Upload or choose a square PNG image (recommended: **512 x 512 pixels**)
5. Click **Save Changes**

### Icon Requirements

- **Format:** PNG (recommended) or any web-compatible image format
- **Size:** 512 x 512 pixels recommended (minimum 192 x 192)
- **Shape:** Square — the browser/OS may apply rounded corners automatically
- **Tip:** Use a simple, recognizable design that looks good at small sizes (as small as 32x32 on taskbars)

### Icon Fallback Chain

If no custom app icon is uploaded, the system falls back to:

1. **WordPress Site Icon** — set in Settings > General > Site Icon
2. **Auto-generated icon** — a colored square with your site name initials (e.g., "AS" for "a3rev Software")

### When Users Need to Reinstall

Changing the app icon in the admin panel does **not** automatically update the icon on users' desktops. The icon is captured at the moment of installation. To pick up a new icon, users must:

1. **Uninstall** the existing desktop app (see Section 5)
2. **Reinstall** from the Staff AI page (see Section 2)

> **Important:** Simply deleting the desktop shortcut does not uninstall the app. You must follow the full uninstall process below.

---

## 5. Uninstalling the Desktop App

### Google Chrome

**Method 1 — From inside the app:**
1. Open the Staff AI desktop app
2. Click the **three-dot menu** in the app's title bar
3. Select **"Uninstall Staff AI"**
4. Confirm when prompted

**Method 2 — From Chrome:**
1. Open Chrome and navigate to `chrome://apps`
2. Right-click the **Staff AI** icon
3. Select **"Remove from Chrome"**
4. Check "Also clear data from Chrome" if you want a clean removal
5. Click **Remove**

**Method 3 — From Windows Settings:**
1. Open **Windows Settings > Apps > Installed apps**
2. Search for "Staff AI"
3. Click the three-dot menu next to it and select **Uninstall**

### Microsoft Edge

**Method 1 — From inside the app:**
1. Open the Staff AI desktop app
2. Click the **three-dot menu** in the app's title bar
3. Select **"Uninstall Staff AI"** or **"App settings"** then **Uninstall**

**Method 2 — From Edge:**
1. Open Edge and navigate to `edge://apps`
2. Find **Staff AI** in the list
3. Click the **three-dot menu** next to it
4. Select **Uninstall**

**Method 3 — From Windows Settings:**
1. Open **Windows Settings > Apps > Installed apps**
2. Search for "Staff AI"
3. Click the three-dot menu next to it and select **Uninstall**

### Mozilla Firefox

If you created a bookmark shortcut:
1. Simply delete the shortcut from your desktop
2. Optionally remove the bookmark from Firefox

---

## 6. Troubleshooting

### Install button not appearing

- **Already installed:** If you see "Open in app" in the address bar, the app is already installed. You can uninstall and reinstall if you need to update the icon.
- **HTTP site:** PWA installation requires HTTPS (except for `localhost`). Make sure your site uses HTTPS.
- **Firefox:** Desktop PWA install is not supported in Firefox — use Chrome or Edge.
- **Incognito/private mode:** PWA installation is not available in private browsing windows.

### App shows wrong name or icon

The app name and icon are captured at install time. To update:
1. Ask your administrator to update the icon in **Digital Employees > Branding > Web App Icon**
2. Uninstall the app (Section 5)
3. Reinstall the app (Section 2)

### App shows login page on every launch

- Your session may have expired — log in and the app will resume normally
- Check that cookies are not being cleared by your browser or security software
- Ensure your browser allows cookies for your company's domain

### App won't launch or shows blank screen

1. Check your internet connection — Staff AI requires an active connection
2. Try uninstalling and reinstalling the app
3. Clear your browser's cache for the Staff AI URL
4. Contact your administrator if the issue persists
