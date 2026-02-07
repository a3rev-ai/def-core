# Digital Employee Framework  
## DEF Capabilities & Access Model (Authoritative)

Capabilities are evaluated **before** any channel routing, Employee selection, document retrieval, or tool execution.

**Audience:** DEF core developers & platform architects  
**Purpose:** Define how DEF access is controlled without relying on WordPress role names  
**Status:** Required for secure deployment

Suggested path:
`/docs/platform/security/DEF_CAPABILITIES_AND_ROLES.md`

---

## 1. Why DEF Uses Capabilities (Not WordPress Roles)

WordPress role names are unreliable for security decisions because:
- sites frequently define custom roles (including “admin-like” roles)
- agencies/contractors may have Administrator but must not see management-only information
- some users should access Staff AI but never access wp-admin

Therefore:

> **Capabilities are the enforcement mechanism. WordPress roles are not authoritative.**

---

## 2. Core DEF Capabilities (v1)

def-core MUST register the following capabilities.

### 2.1 `def_dashboard_access`
Grants access to the **DEF admin platform UI** in wp-admin (Digital Employees menu and pages).

Use this to allow only those users who need acccess to the Digital Employees wp-admin menu and pages, regardless of their WordPress user role.

---

### 2.2 `def_staff_access`
Grants access to the **Staff AI Channel** and routes the user to the
**Staff Knowledge Assistant**.

---

### 2.3 `def_management_access`

Grants access to **management-only internal knowledge** and routes the user to the
**Management Knowledge Assistant** in the Staff AI Channel.

Rules:
- `def_management_access` implicitly includes all staff-level access
- Users with this capability MUST be treated as having staff access for all routing and retrieval purposes
- `def_staff_access` does not need to be assigned separately when `def_management_access` is present

---

## 3. Initial Capability Assignment & Safety Guarantees

### 3.1 Plugin Activation Rules

When the def-core plugin is installed and activated:

- The activating user (who must be a WordPress Administrator to install plugins)
  MUST automatically be granted `def_dashboard_access`.

This guarantees that:
- at least one user can access the Digital Employee Framework admin UI
- the platform cannot be locked out on first install

---

### 3.2 Minimum Access Requirement

At all times:
- At least **one active WordPress user** MUST have `def_dashboard_access`.

The system MUST prevent a configuration state where:
- no users have `def_dashboard_access`

If an admin attempts to remove dashboard access from the last remaining user,
the action MUST be blocked with a clear warning.

---

## 4. Access Control UI Behaviour (Authoritative)

The **Security & Access → Access Control** screen MUST provide clear visibility and control over DEF capabilities.

### 4.1 Required UI Lists

The interface MUST display:

- A list of users with **Dashboard Access** (`def_dashboard_access`)
- A list of users with **Staff AI Access** (`def_staff_access`)
- A list of users with **Management AI Access** (`def_management_access`)

These lists may overlap:
- A user may have dashboard access without Staff AI access
- A user may have Staff AI access without dashboard access
- A user with Management access may also have dashboard access
- Capabilities are independent and additive

---

### 4.2 Required Actions

For each capability group, the UI MUST allow:

- **ADD**
  - Select an existing WordPress user
  - Grant the selected capability

- **EDIT**
  - Modify which DEF capabilities the user has

- **REMOVE**
  - Revoke a capability from a user
  - Subject to safety rules (cannot remove the last dashboard admin)

All changes MUST take effect immediately.

---

### 4.3 Important Rules

- Capability assignment is independent of WordPress role names
- WordPress Administrators do NOT automatically receive DEF capabilities
  (except for the activating user on install)
- UI must clearly indicate when a user has multiple DEF capabilities


## 5. Capability Enforcement Rules

These rules are non-negotiable:

- Capability checks MUST run:
  - before channel routing is finalised
  - before any internal document retrieval
  - before any tool execution
- UI must not bypass capability enforcement
- If capability checks fail, access must be denied safely

---

## 6. Where Capabilities Are Assigned (Admin UX Requirement)

Capabilities are assigned **directly to WordPress users** via the DEF platform UI.

Authoritative location:

**Digital Employees → Security & Access → Access Control**

This screen MUST support:
- searching/selecting WP users
- granting/removing:
  - Dashboard Access (`def_dashboard_access`)
  - Staff AI Access (`def_staff_access`)
  - Management AI Access (`def_management_access`)
- reviewing who currently has access
- warnings about Management access (“grant only to trusted users”)

---

## 7. Staff Who Never Use wp-admin

v1 requirements:
- Staff AI requires authentication
- staff must have WordPress user account (any role)

Future (v2+):
- SSO / IdP integration
- magic-link login
- external identity providers

These must still map to DEF capabilities.

---

## 8. Audit & Logging Expectations

When Staff AI or Setup Assistant is used, logs should record (event-based):
- user ID (or anonymous)
- channel used
- Employee routed
- outcome (allowed/blocked/escalated)
- timestamp

Do not store sensitive content in logs by default.

See:
- `LOGGING_RULES.md`
- `PRIVACY_POSTURE.md`

---

## 9. Non-Negotiable Rule

> **WordPress Administrator ≠ trusted user.  
> DEF capabilities are the only authority.**
> The Setup Assistant is not a privileged feature.
> If a user can access the Digital Employee Framework dashboard, they must also be able to access the Setup Assistant.

---

## 10. Optional / Future Notes (Not v1 enforcement)

DEF may optionally provide “convenience roles” in future versions, but:
- they must never be required for security enforcement
- they must never be the primary mechanism described to developers

v1 enforcement must remain capability-first and user-assigned.
