export interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface Paginated<T> {
    data: T[];
    meta: PageMeta;
}

export interface Ref {
    id: number;
    name: string;
}

export interface Can {
    update: boolean;
    delete?: boolean;
    disable?: boolean;
    reset_mfa?: boolean;
}

export interface Me {
    user: {
        id: number;
        login: string;
        name: string;
        email: string | null;
        must_change_password: boolean;
        last_login_at: string | null;
        employee: { id: number; full_name: string } | null;
        home: string;
    };
    permissions: string[];
    role_assignments: { role: string; scope_type: ScopeTypeCode; scope_label: string | null }[];
    can: { manage_roles: boolean; view_roles: boolean };
    mfa: MfaSummary;
    available_permissions: string[];
}

export type MfaStatus = 'ok' | 'verify_required' | 'setup_required' | 'setup_recommended';

export interface MfaSummary {
    status: MfaStatus;
    enabled: boolean;
    required: boolean;
    grace_until: string | null;
    recovery_codes_left: number;
    trusted_devices: number;
}

export interface MfaSetup {
    secret: string;
    otpauth_url: string;
    qr_svg: string;
}

export type ScopeTypeCode = 'group' | 'company' | 'dealership' | 'department' | 'own';
export type OrgUnitType = 'group' | 'company' | 'dealership' | 'department';

export interface OrgNode {
    type: OrgUnitType;
    id: number;
    name: string;
    is_active: boolean;
    selectable: boolean;
    children: OrgNode[];
}

export interface CompanyGroup {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    can: Can;
}

export interface Company {
    id: number;
    group_id: number;
    code: string;
    name: string;
    legal_name: string | null;
    is_active: boolean;
    dealerships_count?: number;
    departments_count?: number;
    created_at: string;
    updated_at: string;
    can: Can;
}

export interface Dealership {
    id: number;
    group_id: number;
    company_id: number;
    company?: Ref;
    code: string;
    name: string;
    city: string | null;
    address: string | null;
    is_active: boolean;
    departments_count?: number;
    can: Can;
}

export interface Department {
    id: number;
    group_id: number;
    company_id: number;
    dealership_id: number | null;
    company?: Ref;
    dealership?: Ref | null;
    code: string;
    name: string;
    kind: string | null;
    is_active: boolean;
    can: Can;
}

export interface EmployeeAssignment {
    id: number;
    employee_id: number;
    company: Ref;
    dealership: Ref | null;
    department: Ref | null;
    position: string | null;
    is_primary: boolean;
    starts_on: string;
    ends_on: string | null;
    is_current: boolean;
}

export interface Employee {
    id: number;
    group_id: number;
    company_id: number;
    company?: Ref;
    full_name: string;
    last_name: string;
    first_name: string;
    middle_name: string | null;
    personnel_number: string | null;
    email: string | null;
    work_phone: string | null;
    phone_extension: string | null;
    is_active: boolean;
    assignments?: EmployeeAssignment[];
    user?: { id: number; login: string } | null;
    can: Can;
}

export interface UserRoleScope {
    id: number;
    role: Ref;
    scope_type: ScopeTypeCode;
    scope_id: number;
    scope_label: string | null;
    can_revoke: boolean;
}

export interface User {
    id: number;
    login: string;
    name: string;
    email: string | null;
    is_active: boolean;
    must_change_password: boolean;
    employee?: { id: number; full_name: string } | null;
    mfa_enabled: boolean;
    home: { group?: Ref; company?: Ref | null; dealership?: Ref | null; department?: Ref | null };
    role_scopes?: UserRoleScope[];
    last_login_at: string | null;
    disabled_at: string | null;
    created_at: string;
    can: Can;
}

export interface Role {
    id: number;
    code: string;
    name: string;
    description: string | null;
    is_system: boolean;
    allowed_scope_types: ScopeTypeCode[];
    permissions?: string[];
    assignments_count?: number;
    lock_version: number;
    updated_at: string;
    can: Can;
}

export interface PermissionInfo {
    code: string;
    category: string;
    name: string;
    module_available: boolean;
}

export interface PermissionRegistry {
    data: PermissionInfo[];
    scope_types: { code: ScopeTypeCode; name: string }[];
}

export interface AuditEvent {
    id: number;
    occurred_at: string;
    actor: { id: number; login: string | null } | null;
    action: string;
    subject_type: string | null;
    subject_id: number | null;
    subject_label: string | null;
    company_id: number | null;
    changes: Record<string, unknown> | null;
    reason: string | null;
    ip_address: string | null;
    user_agent: string | null;
}

export interface SessionInfo {
    id: string;
    ip_address: string | null;
    user_agent: string | null;
    last_activity: string;
    is_current: boolean;
}
