import { http } from './http';
import type {
    AuditEvent,
    MfaSetup,
    MfaSummary,
    Company,
    CompanyGroup,
    Dealership,
    Department,
    Employee,
    EmployeeAssignment,
    Me,
    OrgNode,
    Paginated,
    PermissionRegistry,
    Role,
    SessionInfo,
    User,
} from './types';

export type Query = Record<string, string | number | boolean | null | undefined>;
type Payload = Record<string, unknown>;

/** Пустые значения фильтров в запрос не отправляются. */
function clean(query: Query = {}): Query {
    return Object.fromEntries(Object.entries(query).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}

const get = async <T>(url: string, query?: Query) => (await http.get<T>(url, { params: clean(query) })).data;
const post = async <T>(url: string, body?: Payload) => (await http.post<T>(url, body)).data;
const patch = async <T>(url: string, body: Payload) => (await http.patch<T>(url, body)).data;
const del = async <T = void>(url: string) => (await http.delete<T>(url)).data;

/** CRUD-обёртка для ресурса справочника. */
function resource<T>(base: string) {
    return {
        list: (query?: Query) => get<Paginated<T>>(base, query),
        show: (id: number) => get<T>(`${base}/${id}`),
        create: (body: Payload) => post<T>(base, body),
        update: (id: number, body: Payload) => patch<T>(`${base}/${id}`, body),
    };
}

export const authApi = {
    me: () => get<Me>('/auth/me'),
    login: (login: string, password: string) => post<Me>('/auth/login', { login, password }),
    logout: () => post<void>('/auth/logout'),
    changePassword: (body: { current_password: string; password: string; password_confirmation: string }) =>
        post<void>('/auth/password', body),
    requestReset: (login: string) => post<{ message: string }>('/auth/password-reset/request', { login }),
    confirmReset: (body: { token: string; password: string; password_confirmation: string }) =>
        post<void>('/auth/password-reset/confirm', body),
    sessions: () => get<{ data: SessionInfo[] }>('/auth/sessions'),
    terminateSession: (id: string) => del(`/auth/sessions/${id}`),
    terminateOtherSessions: () => del<{ terminated: number }>('/auth/sessions'),
};

export const mfaApi = {
    show: () => get<MfaSummary>('/auth/mfa'),
    setup: () => post<MfaSetup>('/auth/mfa/setup'),
    confirm: (code: string) => post<{ recovery_codes: string[]; mfa: MfaSummary }>('/auth/mfa/confirm', { code }),
    verify: (body: { code?: string; recovery_code?: string; trust_device?: boolean }) =>
        post<{ mfa: MfaSummary }>('/auth/mfa/verify', body),
    regenerateRecoveryCodes: () => post<{ recovery_codes: string[] }>('/auth/mfa/recovery-codes'),
    forgetDevices: () => del<{ forgotten: number }>('/auth/mfa/trusted-devices'),
    disable: () => del<{ mfa: MfaSummary }>('/auth/mfa'),
};

export const orgApi = {
    tree: (permission?: string) => get<{ permission: string; data: OrgNode[] }>('/org/tree', { permission }),
    groups: {
        list: () => get<{ data: CompanyGroup[] }>('/groups'),
        update: (id: number, body: Payload) => patch<CompanyGroup>(`/groups/${id}`, body),
    },
    companies: resource<Company>('/companies'),
    dealerships: resource<Dealership>('/dealerships'),
    departments: resource<Department>('/departments'),
};

export const employeesApi = {
    ...resource<Employee>('/employees'),
    addAssignment: (employeeId: number, body: Payload) => post<EmployeeAssignment>(`/employees/${employeeId}/assignments`, body),
    updateAssignment: (employeeId: number, id: number, body: Payload) =>
        patch<EmployeeAssignment>(`/employees/${employeeId}/assignments/${id}`, body),
    deleteAssignment: (employeeId: number, id: number) => del(`/employees/${employeeId}/assignments/${id}`),
};

export const usersApi = {
    ...resource<User>('/users'),
    setStatus: (id: number, is_active: boolean, reason?: string) => post<User>(`/users/${id}/status`, { is_active, reason }),
    setPassword: (id: number, password: string) => post<{ message: string }>(`/users/${id}/password`, { password }),
    terminateSessions: (id: number) => del<{ terminated: number }>(`/users/${id}/sessions`),
    resetMfa: (id: number, reason: string) => http.delete<User>(`/users/${id}/mfa`, { data: { reason } }).then((r) => r.data),
    grantRole: (id: number, body: { role_id: number; scope_type: string; scope_id?: number | null }) =>
        post<User>(`/users/${id}/role-scopes`, body),
    revokeRole: (id: number, scopeId: number) => del<User>(`/users/${id}/role-scopes/${scopeId}`),
};

export const rolesApi = {
    list: () => get<{ data: Role[] }>('/roles'),
    show: (id: number) => get<Role>(`/roles/${id}`),
    create: (body: Payload) => post<Role>('/roles', body),
    update: (id: number, body: Payload) => patch<Role>(`/roles/${id}`, body),
    remove: (id: number) => del(`/roles/${id}`),
    permissions: () => get<PermissionRegistry>('/permissions'),
};

export const auditApi = {
    list: (query?: Query) => get<Paginated<AuditEvent>>('/audit', query),
};
