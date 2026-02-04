# User Role & Access Management Refactoring Proposal

> **Status**: VALIDATED
> **Date**: 2026-02-04
> **Author**: Claude Code Analysis

## Validated Design Decisions

| Question | Decision |
|----------|----------|
| **Default Member Behavior** | Option B: Members require explicit project assignment |
| **Viewer Role** | Yes, add 4th role "viewer" (read-only) |
| **Permission Inheritance** | Cascade: Project permissions apply to all environments |
| **Global Admin Approach** | Use `is_global_admin` flag (deprecate Root Team concept) |
| **Priority Features** | Environment-level access is priority (moved to Phase 2) |
| **Audit Trail** | Nice-to-have (deferred to future phase) |

---

## Table of Contents
1. [Executive Summary](#executive-summary)
2. [Current State Analysis](#current-state-analysis)
3. [Dokploy Reference Implementation](#dokploy-reference-implementation)
4. [Gap Analysis](#gap-analysis)
5. [Proposed Architecture](#proposed-architecture)
6. [Database Schema Design](#database-schema-design)
7. [Migration Strategy](#migration-strategy)
8. [Implementation Plan](#implementation-plan)
9. [Visual Documentation](#visual-documentation)
10. [Testing Strategy](#testing-strategy)

---

## Executive Summary

### Problem Statement
Coolify's current user and role management system lacks the flexibility needed for PaaS owners to:
- Add users at a global (instance) level
- Assign users to different teams with varying roles
- Control access at the project level within teams
- Implement granular permissions for resources

### Goals
1. **Global User Management**: Manage users at the instance level, then assign them to teams
2. **Flexible Role Assignment**: Different roles per team for the same user
3. **Project-Level Access Control**: Fine-grained access to projects and environments
4. **Resource Permissions**: Granular permissions for specific operations
5. **Backward Compatibility**: Zero-downtime migration for existing users

---

## Current State Analysis

### Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        COOLIFY CURRENT STATE                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   ┌──────────┐        ┌───────────┐        ┌─────────────┐     │
│   │   User   │───────>│ team_user │<───────│    Team     │     │
│   │          │  M:N   │  (pivot)  │  M:N   │             │     │
│   │ - name   │        │ - role    │        │ - name      │     │
│   │ - email  │        │           │        │ - personal  │     │
│   └──────────┘        └───────────┘        └─────────────┘     │
│                              │                    │             │
│                              │                    │             │
│                        ┌─────▼─────┐              │             │
│                        │   Roles   │              │             │
│                        ├───────────┤              │             │
│                        │ • owner   │              │             │
│                        │ • admin   │              │             │
│                        │ • member  │              │             │
│                        └───────────┘              │             │
│                                                   │             │
│                              ┌────────────────────┘             │
│                              │                                  │
│                        ┌─────▼─────┐                           │
│                        │  Project  │                           │
│                        │           │───────┐                   │
│                        │ - team_id │       │                   │
│                        └───────────┘       │                   │
│                              │             │                   │
│                        ┌─────▼─────┐  ┌────▼────┐              │
│                        │Environment│  │ Server  │              │
│                        └───────────┘  └─────────┘              │
│                              │                                  │
│                   ┌──────────┴──────────┐                      │
│                   │                     │                       │
│              ┌────▼────┐           ┌────▼────┐                 │
│              │   App   │           │ Service │                 │
│              └─────────┘           └─────────┘                 │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Current Role System

| Role | Rank | Capabilities |
|------|------|--------------|
| **owner** | 3 | Full team control, can promote to owner, delete team |
| **admin** | 2 | Manage members (not owner), invite at same/lower level |
| **member** | 1 | Read-only team access, cannot manage team |

### New Role System (After Refactoring)

| Role | Rank | Capabilities |
|------|------|--------------|
| **owner** | 4 | Full team control, can promote to owner, delete team |
| **admin** | 3 | Manage members (not owner), invite at same/lower level, full resource access |
| **member** | 2 | Requires explicit project assignment, can perform assigned actions |
| **viewer** | 1 | Read-only access to assigned projects, cannot modify anything |

### Current Limitations

1. **No Global User Management**
   - Users created only through team invitations
   - No instance-level user directory
   - Cannot pre-create users before assigning to teams

2. **Flat Team Permissions**
   - All resources in a team inherit team-level role
   - No project-specific permissions
   - No environment-specific access control

3. **Limited Role Hierarchy**
   - Only 3 roles with fixed capabilities
   - No custom roles or permissions
   - No resource-type specific permissions

4. **Disabled Authorization**
   - Many policies return `true` (disabled)
   - Middleware for resource control commented out
   - Inconsistent authorization enforcement

5. **No Audit Trail**
   - No logging of permission changes
   - No visibility into access patterns

### Key Files

| Component | Location |
|-----------|----------|
| User Model | `app/Models/User.php` |
| Team Model | `app/Models/Team.php` |
| Role Enum | `app/Enums/Role.php` |
| Team Policy | `app/Policies/TeamPolicy.php` |
| App Policy | `app/Policies/ApplicationPolicy.php` |
| Auth Provider | `app/Providers/AuthServiceProvider.php` |
| Member Component | `app/Livewire/Team/Member.php` |

---

## Dokploy Reference Implementation

### Key Strengths

1. **Two-Tier Authorization Model**
   - Tier 1: Role-based bypass (Owner/Admin = full access)
   - Tier 2: Granular permissions for Members

2. **Organization Isolation**
   - Multi-tenant workspace model
   - Active organization context in session
   - Resource filtering by organization

3. **Granular Permissions**
   - Boolean capability flags
   - Resource whitelist arrays
   - Environment-level access control

### Dokploy Permission Structure

```
┌─────────────────────────────────────────────────────────────────┐
│                      DOKPLOY PERMISSION MODEL                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   ┌──────────────────────────────────────────────────────┐     │
│   │                   ORGANIZATION                        │     │
│   │                                                       │     │
│   │  ┌─────────┐  ┌─────────┐  ┌─────────┐              │     │
│   │  │  OWNER  │  │  ADMIN  │  │ MEMBER  │              │     │
│   │  │         │  │         │  │         │              │     │
│   │  │ Full    │  │ Full    │  │ Limited │              │     │
│   │  │ Access  │  │ Access  │  │ Access  │              │     │
│   │  └─────────┘  └─────────┘  └────┬────┘              │     │
│   │                                  │                   │     │
│   │                    ┌─────────────┴──────────────┐   │     │
│   │                    │     GRANULAR PERMISSIONS    │   │     │
│   │                    ├─────────────────────────────┤   │     │
│   │                    │ □ Create Projects           │   │     │
│   │                    │ □ Delete Projects           │   │     │
│   │                    │ □ Create Services           │   │     │
│   │                    │ □ Delete Services           │   │     │
│   │                    │ □ Access Docker             │   │     │
│   │                    │ □ Access SSH Keys           │   │     │
│   │                    │ □ API/CLI Access            │   │     │
│   │                    │ □ Traefik Configuration     │   │     │
│   │                    │                             │   │     │
│   │                    │ + Project Access Array      │   │     │
│   │                    │ + Service Access Array      │   │     │
│   │                    │ + Environment Access Array  │   │     │
│   │                    └─────────────────────────────┘   │     │
│   │                                                       │     │
│   └──────────────────────────────────────────────────────┘     │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Lessons Learned

| Aspect | Dokploy Approach | Adaptation for Coolify |
|--------|------------------|------------------------|
| Auth Framework | better-auth (TypeScript) | Laravel Fortify/Sanctum (keep existing) |
| Multi-tenancy | Organizations | Teams (existing concept) |
| Role Bypass | Owner/Admin bypass | Keep 3-tier, add permissions |
| Permissions | Boolean flags + arrays | Permission model + pivot tables |
| Session | activeOrganizationId | currentTeam() (existing) |

---

## Gap Analysis

### Feature Comparison

| Feature | Current Coolify | Dokploy | Gap |
|---------|----------------|---------|-----|
| Global user management | ❌ | ✅ | **Critical** |
| Multi-team membership | ✅ | ✅ | OK |
| Per-team roles | ✅ | ✅ | OK |
| Project-level access | ❌ | ✅ | **Critical** |
| Environment-level access | ❌ | ✅ | **High** |
| Resource permissions | ❌ | ✅ | **High** |
| Custom roles | ❌ | ❌ | Nice-to-have |
| Permission inheritance | ❌ | ❌ | Nice-to-have |
| Read-only roles | ⚠️ (member) | ⚠️ | Enhance |
| Audit trail | ❌ | ❌ | Medium |

### Priority Matrix

```
                        IMPACT
                   Low         High
              ┌─────────┬─────────────┐
         High │ Custom  │ Global User │
              │ Roles   │ Project ACL │
              │         │ Env Access  │
   EFFORT     ├─────────┼─────────────┤
              │ Audit   │ Resource    │
         Low  │ Trail   │ Permissions │
              │         │ Enable Auth │
              └─────────┴─────────────┘
```

---

## Proposed Architecture

### Design Principles

1. **Backward Compatible**: Existing users/teams work without changes
2. **Opt-in Complexity**: Simple by default, granular when needed
3. **DRY Code**: Reusable permission checking utilities
4. **Atomic Migrations**: Small, reversible database changes
5. **Comprehensive Tests**: Each feature fully tested

### New Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        PROPOSED ARCHITECTURE                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │                         INSTANCE LEVEL                                │  │
│  │                                                                       │  │
│  │   ┌─────────────────────────────────────────────────────────┐       │  │
│  │   │                    USERS (Global)                        │       │  │
│  │   │                                                          │       │  │
│  │   │  ┌──────┐  ┌──────┐  ┌──────┐  ┌──────┐  ┌──────┐     │       │  │
│  │   │  │User A│  │User B│  │User C│  │User D│  │User E│     │       │  │
│  │   │  └──┬───┘  └──┬───┘  └──┬───┘  └──┬───┘  └──┬───┘     │       │  │
│  │   │     │         │         │         │         │          │       │  │
│  │   └─────┼─────────┼─────────┼─────────┼─────────┼──────────┘       │  │
│  │         │         │         │         │         │                   │  │
│  └─────────┼─────────┼─────────┼─────────┼─────────┼───────────────────┘  │
│            │         │         │         │         │                       │
│  ┌─────────┼─────────┼─────────┼─────────┼─────────┼───────────────────┐  │
│  │         │         │         │         │         │   TEAM LEVEL       │  │
│  │         │         │         │         │         │                    │  │
│  │  ┌──────▼─────────▼─────────▼─────────┤         │                   │  │
│  │  │           TEAM ALPHA               │         │                   │  │
│  │  │  ┌─────────────────────────────┐   │         │                   │  │
│  │  │  │    team_user (with role)    │   │         │                   │  │
│  │  │  │  A:owner  B:admin  C:member │   │         │                   │  │
│  │  │  └─────────────────────────────┘   │         │                   │  │
│  │  │                │                    │         │                   │  │
│  │  │  ┌─────────────▼─────────────┐     │         │                   │  │
│  │  │  │    PROJECT ACCESS         │     │         │                   │  │
│  │  │  │  (project_user pivot)     │     │         │                   │  │
│  │  │  │  C → Project-1 (deploy)   │     │         │                   │  │
│  │  │  │  C → Project-2 (view)     │     │         │                   │  │
│  │  │  └───────────────────────────┘     │         │                   │  │
│  │  │                                     │         │                   │  │
│  │  │  ┌────────────────────────────┐    │         │                   │  │
│  │  │  │        PROJECTS             │    │         │                   │  │
│  │  │  │  ┌──────────┐ ┌──────────┐ │    │         │                   │  │
│  │  │  │  │Project-1 │ │Project-2 │ │    │         │                   │  │
│  │  │  │  │  ┌────┐  │ │  ┌────┐  │ │    │         │                   │  │
│  │  │  │  │  │prod│  │ │  │dev │  │ │    │         │                   │  │
│  │  │  │  │  │stag│  │ │  │test│  │ │    │         │                   │  │
│  │  │  │  │  └────┘  │ │  └────┘  │ │    │         │                   │  │
│  │  │  │  └──────────┘ └──────────┘ │    │         │                   │  │
│  │  │  └────────────────────────────┘    │         │                   │  │
│  │  └────────────────────────────────────┘         │                   │  │
│  │                                                  │                   │  │
│  │  ┌───────────────────────────────────┬──────────▼─────────────────┐│  │
│  │  │           TEAM BETA               │        TEAM GAMMA          ││  │
│  │  │  D:owner  E:admin                 │   D:member  E:owner        ││  │
│  │  │                                    │                            ││  │
│  │  │  (D is owner here, member there)  │   (E is owner here)        ││  │
│  │  └───────────────────────────────────┴────────────────────────────┘│  │
│  │                                                                     │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Role & Permission Model

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        PERMISSION MODEL                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │                     TEAM-LEVEL ROLES                                 │  │
│   │                                                                      │  │
│   │   ┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐    │  │
│   │   │  OWNER   │    │  ADMIN   │    │  MEMBER  │    │  VIEWER  │    │  │
│   │   │          │    │          │    │          │    │  (NEW)   │    │  │
│   │   │ Bypass   │    │ Bypass   │    │ Check    │    │ Read-    │    │  │
│   │   │ All      │    │ Most     │    │ Project  │    │ Only     │    │  │
│   │   │ Checks   │    │ Checks   │    │ Access   │    │ Access   │    │  │
│   │   └──────────┘    └──────────┘    └──────────┘    └──────────┘    │  │
│   │        │               │               │               │          │  │
│   │        │               │               │               │          │  │
│   │        ▼               ▼               ▼               ▼          │  │
│   │   ┌─────────────────────────────────────────────────────────────┐│  │
│   │   │                  PERMISSION RESOLUTION                       ││  │
│   │   │                                                              ││  │
│   │   │   1. Check team role (owner/admin = bypass)                 ││  │
│   │   │   2. Check project access (if not bypassed)                 ││  │
│   │   │   3. Check environment access (optional)                    ││  │
│   │   │   4. Check resource permissions (if applicable)             ││  │
│   │   │                                                              ││  │
│   │   └─────────────────────────────────────────────────────────────┘│  │
│   │                                                                      │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │                     PROJECT-LEVEL PERMISSIONS                        │  │
│   │                                                                      │  │
│   │   ┌─────────────────────────────────────────────────────────────┐  │  │
│   │   │  project_user pivot table                                    │  │  │
│   │   │                                                              │  │  │
│   │   │  user_id │ project_id │ permissions (JSON)                  │  │  │
│   │   │  ────────┼────────────┼─────────────────────────────────────│  │  │
│   │   │    5     │     1      │ {"view": true, "deploy": true,      │  │  │
│   │   │          │            │  "manage": false, "delete": false}  │  │  │
│   │   │    5     │     2      │ {"view": true, "deploy": false}     │  │  │
│   │   │                                                              │  │  │
│   │   └─────────────────────────────────────────────────────────────┘  │  │
│   │                                                                      │  │
│   │   Permission Types:                                                  │  │
│   │   • view      - Can view project and resources                      │  │
│   │   • deploy    - Can trigger deployments                             │  │
│   │   • manage    - Can modify project settings                         │  │
│   │   • delete    - Can delete resources                                │  │
│   │   • terminal  - Can access server terminal                          │  │
│   │   • secrets   - Can view/edit environment variables                 │  │
│   │                                                                      │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │                    ENVIRONMENT-LEVEL ACCESS (Optional)               │  │
│   │                                                                      │  │
│   │   environment_user pivot (for fine-grained env access)              │  │
│   │                                                                      │  │
│   │   user_id │ environment_id │ permissions                            │  │
│   │   ────────┼────────────────┼────────────────────────────────────────│  │
│   │     5     │       1        │ {"view": true, "deploy": true}         │  │
│   │     5     │       2        │ {"view": true, "deploy": false}        │  │
│   │                                                                      │  │
│   │   (Production access != Staging access)                             │  │
│   │                                                                      │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Authorization Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        AUTHORIZATION FLOW                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────┐                                                           │
│   │   REQUEST   │                                                           │
│   └──────┬──────┘                                                           │
│          │                                                                   │
│          ▼                                                                   │
│   ┌─────────────────────┐                                                   │
│   │ 1. Authenticate     │                                                   │
│   │    (Session/Token)  │                                                   │
│   └──────────┬──────────┘                                                   │
│              │                                                               │
│              ▼                                                               │
│   ┌─────────────────────┐     ┌────────────────┐                           │
│   │ 2. Get Team Context │────>│ currentTeam()  │                           │
│   └──────────┬──────────┘     └────────────────┘                           │
│              │                                                               │
│              ▼                                                               │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │ 3. Check Team Role                                                   │  │
│   │                                                                      │  │
│   │    ┌──────────────────┐                                             │  │
│   │    │ role == owner?   │────YES────> ✅ ALLOW (bypass)               │  │
│   │    └────────┬─────────┘                                             │  │
│   │             │ NO                                                     │  │
│   │             ▼                                                        │  │
│   │    ┌──────────────────┐                                             │  │
│   │    │ role == admin?   │────YES────> Check action type               │  │
│   │    └────────┬─────────┘             │                               │  │
│   │             │ NO                    ▼                               │  │
│   │             │              ┌─────────────────────┐                  │  │
│   │             │              │ Team management?    │                  │  │
│   │             │              │ (invite, roles)     │                  │  │
│   │             │              └──────────┬──────────┘                  │  │
│   │             │                         │                             │  │
│   │             │              YES ◄──────┴──────► NO                   │  │
│   │             │              │                   │                    │  │
│   │             │              ▼                   ▼                    │  │
│   │             │         Check if            ✅ ALLOW                  │  │
│   │             │         target < admin       (resource access)       │  │
│   │             │                                                       │  │
│   │             ▼                                                        │  │
│   │    ┌──────────────────────────────────────────────────────────┐    │  │
│   │    │ 4. Check Project-Level Permission (for members/viewers)  │    │  │
│   │    │                                                           │    │  │
│   │    │    ┌─────────────────────────────────────────────┐       │    │  │
│   │    │    │ Has project_user entry with permission?      │       │    │  │
│   │    │    └────────────────────┬────────────────────────┘       │    │  │
│   │    │                         │                                 │    │  │
│   │    │              YES ◄──────┴──────► NO                       │    │  │
│   │    │              │                   │                        │    │  │
│   │    │              ▼                   ▼                        │    │  │
│   │    │         ✅ ALLOW            ❌ DENY                       │    │  │
│   │    │                                                           │    │  │
│   │    └───────────────────────────────────────────────────────────┘    │  │
│   │                                                                      │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Database Schema Design

### New Tables

#### 1. `permissions` Table (Reference)

```sql
CREATE TABLE permissions (
    id BIGINT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,      -- 'project.view', 'project.deploy'
    description VARCHAR(255),
    resource_type VARCHAR(50) NOT NULL,      -- 'project', 'environment', 'server'
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Seed data
INSERT INTO permissions (name, description, resource_type) VALUES
('project.view', 'View project and resources', 'project'),
('project.deploy', 'Trigger deployments', 'project'),
('project.manage', 'Modify project settings', 'project'),
('project.delete', 'Delete project and resources', 'project'),
('environment.view', 'View environment', 'environment'),
('environment.deploy', 'Deploy to environment', 'environment'),
('environment.secrets', 'View/edit environment variables', 'environment'),
('server.view', 'View server details', 'server'),
('server.terminal', 'Access server terminal', 'server'),
('server.manage', 'Manage server settings', 'server');
```

#### 2. `project_user` Pivot Table

```sql
CREATE TABLE project_user (
    id BIGINT PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    permissions JSON DEFAULT '{}',           -- {"view": true, "deploy": true}
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(project_id, user_id)
);

CREATE INDEX idx_project_user_user ON project_user(user_id);
CREATE INDEX idx_project_user_project ON project_user(project_id);
```

#### 3. `environment_user` Pivot Table (Optional, Phase 2)

```sql
CREATE TABLE environment_user (
    id BIGINT PRIMARY KEY,
    environment_id BIGINT NOT NULL REFERENCES environments(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    permissions JSON DEFAULT '{}',
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE(environment_id, user_id)
);

CREATE INDEX idx_environment_user_user ON environment_user(user_id);
CREATE INDEX idx_environment_user_env ON environment_user(environment_id);
```

### Modified Tables

#### `team_user` Table Additions

```sql
ALTER TABLE team_user ADD COLUMN permissions JSON DEFAULT '{}';
-- Store team-level permission overrides for members
-- Example: {"can_create_projects": true, "can_invite_members": false}
```

#### `users` Table Additions

```sql
ALTER TABLE users ADD COLUMN is_global_admin BOOLEAN DEFAULT FALSE;
ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active';
-- status: 'active', 'suspended', 'pending'

CREATE INDEX idx_users_status ON users(status);
```

### Entity Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        ENTITY RELATIONSHIP DIAGRAM                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────┐         ┌─────────────┐         ┌─────────────┐          │
│   │    users    │         │   team_user │         │    teams    │          │
│   ├─────────────┤         ├─────────────┤         ├─────────────┤          │
│   │ id          │◄───┐    │ id          │    ┌───►│ id          │          │
│   │ name        │    │    │ team_id     │────┘    │ name        │          │
│   │ email       │    └────│ user_id     │         │ personal    │          │
│   │ is_global   │         │ role        │         │ description │          │
│   │ status      │         │ permissions │         └──────┬──────┘          │
│   └──────┬──────┘         └─────────────┘                │                  │
│          │                                               │                  │
│          │                                               │                  │
│          │    ┌──────────────────────────────────────────┘                  │
│          │    │                                                             │
│          │    │         ┌─────────────┐                                    │
│          │    └────────►│  projects   │                                    │
│          │              ├─────────────┤                                    │
│          │              │ id          │◄─────────┐                         │
│          │              │ name        │          │                         │
│          │              │ team_id     │          │                         │
│          │              │ description │          │                         │
│          │              └──────┬──────┘          │                         │
│          │                     │                  │                         │
│          │   ┌─────────────────┘                  │                         │
│          │   │                                    │                         │
│          │   │    ┌─────────────┐    ┌───────────┴───────────┐             │
│          │   └───►│environments │    │     project_user      │             │
│          │        ├─────────────┤    ├───────────────────────┤             │
│          │        │ id          │◄──┐│ id                    │             │
│          │        │ name        │   ││ project_id            │─────────────┘
│          │        │ project_id  │   ││ user_id               │◄────────────┐
│          │        └──────┬──────┘   ││ permissions (JSON)    │             │
│          │               │          │└───────────────────────┘             │
│          │               │          │                                       │
│          │               │          │                                       │
│          │               │   ┌──────┴────────────┐                         │
│          │               │   │  environment_user │                         │
│          │               │   ├───────────────────┤                         │
│          │               │   │ id                │                         │
│          │               └───│ environment_id    │                         │
│          └───────────────────│ user_id           │                         │
│                              │ permissions (JSON)│                         │
│                              └───────────────────┘                         │
│                                                                              │
│   ┌─────────────┐                                                           │
│   │ permissions │   (Reference table - seeded values)                       │
│   ├─────────────┤                                                           │
│   │ id          │                                                           │
│   │ name        │   'project.view', 'project.deploy', etc.                 │
│   │ description │                                                           │
│   │ resource_type│                                                          │
│   └─────────────┘                                                           │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Migration Strategy

### Phase Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        MIGRATION PHASES                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  PHASE 1: Foundation (Non-Breaking)                                  │  │
│   │  ─────────────────────────────────                                   │  │
│   │  • Add new columns to users table (is_global_admin, status)         │  │
│   │  • Add permissions column to team_user table                        │  │
│   │  • Create permissions reference table                               │  │
│   │  • Create project_user pivot table                                  │  │
│   │  • Add Permission model and trait                                   │  │
│   │  • No behavior changes - all defaults maintain current behavior     │  │
│   │                                                                      │  │
│   │  Rollback: Drop new columns/tables                                  │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                              │                                               │
│                              ▼                                               │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  PHASE 2: Authorization + Environment Access (Priority)             │  │
│   │  ───────────────────────────────────────────────                     │  │
│   │  • Add viewer role to Role enum                                     │  │
│   │  • Create environment_user pivot table                              │  │
│   │  • Enable policies one by one (ApplicationPolicy first)            │  │
│   │  • Add authorization checks to Livewire components                  │  │
│   │  • Implement permission cascade (project → environments)           │  │
│   │  • Feature flag for new permission system                           │  │
│   │                                                                      │  │
│   │  Rollback: Disable feature flag                                     │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                              │                                               │
│                              ▼                                               │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  PHASE 3: UI & Management                                            │  │
│   │  ──────────────────────                                              │  │
│   │  • Global user management UI (instance admin)                       │  │
│   │  • Project access management UI                                     │  │
│   │  • Team permission management UI                                    │  │
│   │  • API endpoints for permission management                          │  │
│   │                                                                      │  │
│   │  Rollback: Revert UI changes                                        │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                              │                                               │
│                              ▼                                               │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  PHASE 4: Future Enhancements (Nice-to-Have)                        │  │
│   │  ─────────────────────────────────────────                           │  │
│   │  • Audit trail logging for permission changes                       │  │
│   │  • API token granular permissions                                   │  │
│   │  • Custom role definitions                                          │  │
│   │  • Permission templates for quick setup                             │  │
│   │                                                                      │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Backward Compatibility Guarantees

| Aspect | Current Behavior | After Migration |
|--------|------------------|-----------------|
| User login | Works | Works (unchanged) |
| Team switching | Works | Works (unchanged) |
| Role-based access | owner/admin bypass | owner/admin bypass (same) |
| Member access | All team resources | **Requires explicit project assignment** (migration auto-grants existing access) |
| Invitations | Role only | Role + project permissions |
| API tokens | Team scoped | Team scoped (unchanged) |

### Migration Data Transformation

To ensure zero disruption for existing users:

```php
// Migration: Auto-grant existing members access to all current projects
foreach ($teams as $team) {
    $members = $team->members()->where('role', 'member')->get();
    $projects = $team->projects;

    foreach ($members as $member) {
        foreach ($projects as $project) {
            // Grant full access to maintain current behavior
            ProjectUser::create([
                'user_id' => $member->id,
                'project_id' => $project->id,
                'permissions' => ['view' => true, 'deploy' => true, 'manage' => true, 'delete' => true],
            ]);
        }
    }
}
```

This ensures existing members retain their current access levels after migration.

### Feature Flag Implementation

```php
// config/coolify.php
return [
    'features' => [
        'granular_permissions' => env('COOLIFY_GRANULAR_PERMISSIONS', false),
    ],
];

// Usage in code
if (config('coolify.features.granular_permissions')) {
    // Check project-level permissions
} else {
    // Use team-level role only
}
```

---

## Implementation Plan

### Phase 1: Foundation (Estimated: 2 sprints)

#### Migrations

1. **Add user columns**
   ```
   2024_XX_XX_add_global_admin_to_users.php
   - Add is_global_admin BOOLEAN DEFAULT FALSE
   - Add status VARCHAR(20) DEFAULT 'active'
   ```

2. **Add team_user permissions**
   ```
   2024_XX_XX_add_permissions_to_team_user.php
   - Add permissions JSON DEFAULT '{}'
   ```

3. **Create permissions table**
   ```
   2024_XX_XX_create_permissions_table.php
   - Create table with seeded values
   ```

4. **Create project_user pivot**
   ```
   2024_XX_XX_create_project_user_table.php
   - Create pivot table
   ```

#### Models & Traits

1. **Permission Model** (`app/Models/Permission.php`)
2. **ProjectUser Model** (`app/Models/ProjectUser.php`)
3. **HasProjectAccess trait** (`app/Traits/HasProjectAccess.php`)
4. **ChecksPermissions trait** (`app/Traits/ChecksPermissions.php`)

#### Tests

- Unit tests for permission checking logic
- Migration tests (up/down)
- Model relationship tests

### Phase 2: Authorization + Environment Access (Estimated: 3 sprints)

#### New Role Implementation

1. **Update Role Enum** (`app/Enums/Role.php`)
   - Add `VIEWER` case with rank 1
   - Update other ranks: MEMBER=2, ADMIN=3, OWNER=4

2. **Create environment_user pivot table**
   ```
   2024_XX_XX_create_environment_user_table.php
   - Create pivot table for environment-level permissions
   ```

#### Policy Updates

1. **ApplicationPolicy** - Enable with project-level checks
2. **ProjectPolicy** - Enable with team/project checks
3. **ServerPolicy** - Enable with team checks
4. **ServicePolicy** - Enable with project checks
5. **DatabasePolicy** - Enable with project checks
6. **EnvironmentPolicy** - Enable with cascaded project permissions

#### Middleware Updates

1. **CanUpdateResource** - Enable with permission checks
2. **CanCreateResources** - Enable with permission checks

#### Component Updates

- Add `$this->authorize()` calls to Livewire components
- Add `canGate` and `canResource` to form components

#### Permission Cascade Implementation

```php
// Project permissions cascade to all environments by default
public function hasEnvironmentPermission(Environment $env, string $permission): bool
{
    // Check explicit environment permission first
    if ($this->environmentPermissions()->where('environment_id', $env->id)->exists()) {
        return $this->environmentPermissions()
            ->where('environment_id', $env->id)
            ->first()
            ->permissions[$permission] ?? false;
    }

    // Fall back to project permission (cascade)
    return $this->hasProjectPermission($env->project, $permission);
}
```

### Phase 3: UI & Management (Estimated: 3 sprints)

#### New Views

1. **Global User Management** (`/admin/users`)
   - List all users
   - Create user without team
   - Suspend/activate users
   - Assign to teams

2. **Project Access Management** (`/project/{id}/access`)
   - List users with project access
   - Add/remove user access
   - Set permission levels

3. **Team Permission Settings** (`/team/settings/permissions`)
   - Configure default member permissions
   - View member project access

#### API Endpoints

1. `POST /api/v1/users` - Create global user
2. `GET /api/v1/users` - List all users (admin)
3. `POST /api/v1/projects/{id}/access` - Grant project access
4. `DELETE /api/v1/projects/{id}/access/{userId}` - Revoke access
5. `PUT /api/v1/projects/{id}/access/{userId}` - Update permissions

### Phase 4: Future Enhancements (Estimated: 2 sprints, Nice-to-Have)

1. **Audit trail logging** - Track permission changes with timestamps and actors
2. **API token granular permissions** - Allow fine-grained API access control
3. **Custom role definitions** - Allow teams to define custom roles
4. **Permission templates** - Pre-defined permission sets for common scenarios

---

## Visual Documentation

### User Journey: Adding a New Team Member

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                  USER JOURNEY: ADDING A TEAM MEMBER                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   INSTANCE ADMIN                        TEAM ADMIN                          │
│   ─────────────                         ──────────                          │
│                                                                              │
│   ┌──────────────────┐                                                      │
│   │ 1. Create User   │                                                      │
│   │    Globally      │                                                      │
│   │    (/admin/users)│                                                      │
│   └────────┬─────────┘                                                      │
│            │                                                                 │
│            │  User exists but not                                           │
│            │  assigned to any team                                          │
│            │                                                                 │
│            ▼                                                                 │
│   ┌──────────────────┐                  ┌──────────────────┐               │
│   │ 2. Assign User   │                  │ 2. Invite User   │               │
│   │    to Team       │       OR         │    to Team       │               │
│   │    with Role     │                  │    with Role     │               │
│   └────────┬─────────┘                  └────────┬─────────┘               │
│            │                                      │                         │
│            │                                      │                         │
│            ▼                                      ▼                         │
│   ┌─────────────────────────────────────────────────────────────┐          │
│   │                    User in Team                              │          │
│   │                    (with role: owner/admin/member)           │          │
│   └─────────────────────────────────┬───────────────────────────┘          │
│                                     │                                       │
│                                     │                                       │
│              ┌──────────────────────┴─────────────────────┐                │
│              │                                            │                 │
│              ▼                                            ▼                 │
│   ┌──────────────────────┐                 ┌──────────────────────┐        │
│   │  Role: owner/admin   │                 │   Role: member       │        │
│   │                      │                 │                      │        │
│   │  → Full team access  │                 │  → Limited access    │        │
│   └──────────────────────┘                 └──────────┬───────────┘        │
│                                                       │                     │
│                                                       ▼                     │
│                                            ┌──────────────────────┐        │
│                                            │ 3. Assign Project    │        │
│                                            │    Permissions       │        │
│                                            │    (optional)        │        │
│                                            └──────────┬───────────┘        │
│                                                       │                     │
│                                                       ▼                     │
│                                            ┌──────────────────────┐        │
│                                            │  Member can access   │        │
│                                            │  specific projects   │        │
│                                            │  with set permissions│        │
│                                            └──────────────────────┘        │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Permission Resolution Example

```
┌─────────────────────────────────────────────────────────────────────────────┐
│              PERMISSION RESOLUTION: CAN USER DEPLOY TO PROJECT?             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   User: alice@example.com                                                   │
│   Action: Deploy Application in Project "WebApp"                            │
│   Team: "Production Team"                                                   │
│                                                                              │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  Step 1: Get team role                                               │  │
│   │                                                                      │  │
│   │  SELECT role FROM team_user                                         │  │
│   │  WHERE user_id = 1 AND team_id = 5                                  │  │
│   │                                                                      │  │
│   │  Result: role = 'member'                                            │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                              │                                               │
│                              ▼                                               │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  Step 2: Check role bypass                                           │  │
│   │                                                                      │  │
│   │  Is role 'owner' or 'admin'?                                        │  │
│   │                                                                      │  │
│   │  Result: NO (role is 'member')                                      │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                              │                                               │
│                              ▼                                               │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  Step 3: Check project-level permission                              │  │
│   │                                                                      │  │
│   │  SELECT permissions FROM project_user                               │  │
│   │  WHERE user_id = 1 AND project_id = 10                              │  │
│   │                                                                      │  │
│   │  Result: {"view": true, "deploy": true, "manage": false}            │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                              │                                               │
│                              ▼                                               │
│   ┌─────────────────────────────────────────────────────────────────────┐  │
│   │  Step 4: Evaluate permission                                         │  │
│   │                                                                      │  │
│   │  Has 'deploy' permission? YES                                       │  │
│   │                                                                      │  │
│   │  ✅ ALLOW - User can deploy to this project                         │  │
│   └─────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### UI Wireframe: Project Access Management

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Project: WebApp > Access Management                                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  Team members with access:                                           │   │
│  │                                                                      │   │
│  │  ┌──────────────────────────────────────────────────────────────┐   │   │
│  │  │ USER           │ TEAM ROLE │ VIEW │ DEPLOY │ MANAGE │ DELETE│   │   │
│  │  ├──────────────────────────────────────────────────────────────┤   │   │
│  │  │ john@acme.com  │ owner     │  ✓   │   ✓    │   ✓    │   ✓   │   │   │
│  │  │                │           │ (all permissions via role)      │   │   │
│  │  ├──────────────────────────────────────────────────────────────┤   │   │
│  │  │ jane@acme.com  │ admin     │  ✓   │   ✓    │   ✓    │   ✓   │   │   │
│  │  │                │           │ (all permissions via role)      │   │   │
│  │  ├──────────────────────────────────────────────────────────────┤   │   │
│  │  │ alice@acme.com │ member    │  ✓   │   ✓    │   ☐    │   ☐   │   │   │
│  │  │                │           │ [Edit Permissions] [Remove]     │   │   │
│  │  ├──────────────────────────────────────────────────────────────┤   │   │
│  │  │ bob@acme.com   │ member    │  ✓   │   ☐    │   ☐    │   ☐   │   │   │
│  │  │                │           │ [Edit Permissions] [Remove]     │   │   │
│  │  └──────────────────────────────────────────────────────────────┘   │   │
│  │                                                                      │   │
│  │  ┌───────────────────────────────────────────┐                      │   │
│  │  │  + Add Team Member to Project             │                      │   │
│  │  └───────────────────────────────────────────┘                      │   │
│  │                                                                      │   │
│  │  Members without explicit project access can only see projects      │   │
│  │  if team-level settings allow default access.                       │   │
│  │                                                                      │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Testing Strategy

### Unit Tests

```php
// tests/Unit/Permissions/PermissionCheckTest.php
it('allows owner to perform any action', function () {
    $user = Mockery::mock(User::class);
    $user->shouldReceive('roleInTeam')->andReturn('owner');

    expect($user->canPerform('deploy', $project))->toBeTrue();
});

it('allows admin to perform most actions', function () {
    $user = Mockery::mock(User::class);
    $user->shouldReceive('roleInTeam')->andReturn('admin');

    expect($user->canPerform('deploy', $project))->toBeTrue();
});

it('checks project permissions for members', function () {
    $user = Mockery::mock(User::class);
    $user->shouldReceive('roleInTeam')->andReturn('member');
    $user->shouldReceive('hasProjectPermission')
         ->with($project, 'deploy')
         ->andReturn(true);

    expect($user->canPerform('deploy', $project))->toBeTrue();
});

it('denies member without project permission', function () {
    $user = Mockery::mock(User::class);
    $user->shouldReceive('roleInTeam')->andReturn('member');
    $user->shouldReceive('hasProjectPermission')
         ->with($project, 'deploy')
         ->andReturn(false);

    expect($user->canPerform('deploy', $project))->toBeFalse();
});
```

### Feature Tests

```php
// tests/Feature/Permissions/ProjectAccessTest.php
it('allows team admin to grant project access', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create();

    $team->users()->attach($admin, ['role' => 'admin']);
    $team->users()->attach($member, ['role' => 'member']);

    actingAs($admin);

    $response = $this->post("/projects/{$project->id}/access", [
        'user_id' => $member->id,
        'permissions' => ['view' => true, 'deploy' => true],
    ]);

    $response->assertSuccessful();

    expect($member->fresh()->hasProjectPermission($project, 'deploy'))
        ->toBeTrue();
});

it('prevents member from granting project access', function () {
    $member = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create();

    $team->users()->attach($member, ['role' => 'member']);
    $team->users()->attach($otherMember, ['role' => 'member']);

    actingAs($member);

    $response = $this->post("/projects/{$project->id}/access", [
        'user_id' => $otherMember->id,
        'permissions' => ['view' => true],
    ]);

    $response->assertForbidden();
});
```

### Migration Tests

```php
// tests/Feature/Migrations/PermissionMigrationTest.php
it('migrates existing users without breaking access', function () {
    // Create existing user with team membership
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->create();

    $team->users()->attach($user, ['role' => 'member']);

    // Run migration
    Artisan::call('migrate');

    // User should still have access (granular permissions disabled by default)
    $user->refresh();

    expect($user->teams)->toHaveCount(1);
    expect($user->roleInTeam($team->id))->toBe('member');
});
```

---

## Validated Design Decisions (Answered Questions)

| # | Question | Decision | Rationale |
|---|----------|----------|-----------|
| 1 | **Default Member Behavior** | Option B: Require explicit project assignment | Provides better security and control |
| 2 | **Viewer Role** | Yes, add as 4th role | Clean separation between read-only and action-capable users |
| 3 | **Permission Inheritance** | Cascade from project to environments | Simpler UX, reduces configuration overhead |
| 4 | **Global Admin** | Use `is_global_admin` flag | Cleaner than Root Team concept, more intuitive |
| 5 | **API Token Permissions** | Inherit from user (future enhancement) | Keep current behavior, enhance later |
| 6 | **Audit Trail** | Nice-to-have (deferred) | Focus on core permission system first |

---

## Next Steps

1. ~~**Review and Validate**~~: ✅ Completed - decisions validated
2. ~~**Prioritize Features**~~: ✅ Completed - env-level is priority
3. **Create Implementation Tasks**: Break down into atomic PRs
4. **Technical Spike**: Prototype permission checking trait
5. **Migration Testing**: Test migration on staging environment
6. **Documentation**: Update user documentation

---

## Implementation Checklist

### Phase 1: Foundation
- [ ] Migration: Add `is_global_admin` and `status` columns to users table
- [ ] Migration: Add `permissions` JSON column to team_user table
- [ ] Migration: Create `permissions` reference table with seed data
- [ ] Migration: Create `project_user` pivot table
- [ ] Migration: Auto-grant existing members access to all projects (backward compat)
- [ ] Model: Create `Permission` model
- [ ] Model: Create `ProjectUser` pivot model
- [ ] Trait: Create `HasProjectAccess` trait for User model
- [ ] Trait: Create `ChecksPermissions` trait for authorization logic
- [ ] Tests: Unit tests for permission checking
- [ ] Tests: Migration rollback tests

### Phase 2: Authorization + Environment Access
- [ ] Enum: Add `VIEWER` role to `Role` enum
- [ ] Migration: Create `environment_user` pivot table
- [ ] Model: Create `EnvironmentUser` pivot model
- [ ] Implement permission cascade (project → environments)
- [ ] Policy: Enable `ApplicationPolicy` with project checks
- [ ] Policy: Enable `ProjectPolicy` with team/project checks
- [ ] Policy: Enable `ServerPolicy` with team checks
- [ ] Policy: Enable `ServicePolicy` with project checks
- [ ] Policy: Enable `DatabasePolicy` with project checks
- [ ] Policy: Enable `EnvironmentPolicy` with cascaded permissions
- [ ] Middleware: Enable `CanUpdateResource`
- [ ] Middleware: Enable `CanCreateResources`
- [ ] Components: Add `authorize()` calls to Livewire components
- [ ] Components: Add `canGate`/`canResource` to form components
- [ ] Tests: Feature tests for all policies
- [ ] Tests: Integration tests for permission cascade

### Phase 3: UI & Management
- [ ] View: Global user management (`/admin/users`)
- [ ] View: Project access management (`/project/{id}/access`)
- [ ] View: Team permission settings (`/team/settings/permissions`)
- [ ] Component: User creation form (global)
- [ ] Component: User-to-team assignment
- [ ] Component: Project permission editor
- [ ] API: `POST /api/v1/users` - Create global user
- [ ] API: `GET /api/v1/users` - List all users
- [ ] API: `POST /api/v1/projects/{id}/access` - Grant access
- [ ] API: `DELETE /api/v1/projects/{id}/access/{userId}` - Revoke access
- [ ] API: `PUT /api/v1/projects/{id}/access/{userId}` - Update permissions
- [ ] Documentation: User guide for permission management
- [ ] Tests: E2E tests for UI flows

### Phase 4: Future Enhancements (Nice-to-Have)
- [ ] Audit trail logging
- [ ] API token granular permissions
- [ ] Custom role definitions
- [ ] Permission templates

---

*Document Version: 2.0 (Validated)*
*Last Updated: 2026-02-04*
