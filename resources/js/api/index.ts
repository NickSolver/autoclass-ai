import { http } from './http';
import type {
    AnalyticsReport,
    AppealItem,
    CriterionChange,
    ReviewItem,
    PageMeta,
    ChecklistChange,
    ChecklistDetail,
    ChecklistSummary,
    ChecklistTestResult,
    ChecklistVersion,
    GraphError,
    SimulationResult,
    WorkflowCatalog,
    WorkflowDetail,
    WorkflowGraph,
    WorkflowSummary,
    WorkflowVersion,
    AnalysisResponse,
    ProviderCallLog,
    ProviderConfig,
    ProvidersOverview,
    TranscriptResponse,
    AuditEvent,
    Call,
    CallSummary,
    UploadSettings,
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

export interface UploadMeta {
    company_id?: number | null;
    dealership_id?: number | null;
    department_id?: number | null;
    employee_id?: number | null;
    direction?: string | null;
    started_at?: string | null;
    started_at_tz?: string | null;
    note?: string | null;
}

export const callsApi = {
    list: (query?: Query) => get<Paginated<Call>>('/calls', query),
    summary: (query?: Query) => get<CallSummary>('/calls/summary', query),
    show: (id: number) => get<Call>(`/calls/${id}`),
    update: (id: number, body: Payload) => patch<Call>(`/calls/${id}`, body),
    reprocess: (id: number) => post<Call>(`/calls/${id}/reprocess`),
    exclude: (id: number, reason: string) => post<Call>(`/calls/${id}/exclude`, { reason }),
    uploadSettings: () => get<UploadSettings>('/calls/uploads/settings'),
    /**
     * Загрузка одного файла. Ключ идемпотентности задаётся на файл и повторно используется при
     * повторной попытке — сервер не создаст второй звонок.
     */
    upload: async (file: File, meta: UploadMeta, idempotencyKey: string, confirmDuplicate: boolean, onProgress: (percent: number) => void) => {
        const form = new FormData();
        form.append('file', file);
        Object.entries(meta).forEach(([k, v]) => v !== null && v !== undefined && v !== '' && form.append(k, String(v)));
        if (confirmDuplicate) form.append('confirm_duplicate', '1');
        const response = await http.post<Call>('/calls/uploads', form, {
            headers: { 'Idempotency-Key': idempotencyKey },
            onUploadProgress: (e) => e.total && onProgress(Math.round((e.loaded / e.total) * 100)),
        });
        return response.data;
    },
    transcript: (id: number, version?: number) => get<TranscriptResponse>(`/calls/${id}/transcript`, { version }),
    analysis: (id: number, run?: number) => get<AnalysisResponse>(`/calls/${id}/analysis`, { run }),
    /** Адрес записи: прослушивание идёт через API с проверкой прав, без публичных ссылок. */
    audioUrl: (id: number, download = false) => `/api/v1/calls/${id}/audio${download ? '?download=1' : ''}`,
};

export const aiApi = {
    overview: () => get<ProvidersOverview>('/ai-providers'),
    create: (body: Payload) => post<ProviderConfig>('/ai-providers', body),
    update: (id: number, body: Payload) => patch<ProviderConfig>(`/ai-providers/${id}`, body),
    /** Ключ передаётся только на сервер и никогда не возвращается. */
    setSecret: async (id: number, secret: string | Record<string, string>) =>
        (await http.put<ProviderConfig>(`/ai-providers/${id}/secret`, typeof secret === 'string' ? { secret } : { secrets: secret })).data,
    clearSecret: (id: number) => del<ProviderConfig>(`/ai-providers/${id}/secret`),
    setRole: (id: number, role: string) => post<ProviderConfig>(`/ai-providers/${id}/role`, { role }),
    test: (id: number) => post<{ ok: boolean; message: string }>(`/ai-providers/${id}/test`),
    setExternalProcessing: async (enabled: boolean, reason: string) =>
        (await http.put<ProvidersOverview['external_processing']>('/ai-settings/external-processing', { enabled, reason })).data,
    calls: (query?: Query) => get<Paginated<ProviderCallLog>>('/ai-provider-calls', query),
};

const put = async <T>(url: string, body: Payload) => (await http.put<T>(url, body)).data;

export const checklistsApi = {
    list: () => get<{ data: ChecklistSummary[] }>('/checklists'),
    show: (id: number, version?: number) => get<ChecklistDetail>(`/checklists/${id}`, { version }),
    create: (body: Payload) => post<ChecklistSummary>('/checklists', body),
    update: (id: number, body: Payload) => patch<ChecklistSummary>(`/checklists/${id}`, body),
    saveDraft: (id: number, versionId: number, body: Payload) => put<ChecklistVersion>(`/checklists/${id}/versions/${versionId}`, body),
    publish: (id: number, versionId: number) => post<ChecklistVersion>(`/checklists/${id}/versions/${versionId}/publish`),
    newDraft: (id: number) => post<ChecklistVersion>(`/checklists/${id}/versions`),
    compare: (id: number, from: number, to: number) =>
        get<{ from: number; to: number; changes: ChecklistChange[] }>(`/checklists/${id}/compare`, { from, to }),
    test: (id: number, versionId: number, callId: number) => post<ChecklistTestResult>(`/checklists/${id}/versions/${versionId}/test`, { call_id: callId }),
};

export const workflowsApi = {
    catalog: (companyId?: number | null) => get<WorkflowCatalog>('/workflows/catalog', { company_id: companyId }),
    list: () => get<{ data: WorkflowSummary[] }>('/workflows'),
    show: (id: number, version?: number) => get<WorkflowDetail>(`/workflows/${id}`, { version }),
    create: (body: Payload) => post<WorkflowSummary>('/workflows', body),
    update: (id: number, body: Payload) => patch<WorkflowSummary>(`/workflows/${id}`, body),
    saveDraft: (id: number, versionId: number, graph: WorkflowGraph, lockVersion: number) =>
        put<WorkflowVersion>(`/workflows/${id}/versions/${versionId}`, { graph: graph as unknown as Payload, lock_version: lockVersion }),
    validate: (id: number, versionId: number) => post<{ errors: GraphError[] }>(`/workflows/${id}/versions/${versionId}/validate`),
    simulate: (id: number, versionId: number, body: { input?: Record<string, unknown>; call_id?: number | null }) =>
        post<SimulationResult>(`/workflows/${id}/versions/${versionId}/simulate`, body),
    publish: (id: number, versionId: number) => post<WorkflowVersion>(`/workflows/${id}/versions/${versionId}/publish`),
    newDraft: (id: number) => post<WorkflowVersion>(`/workflows/${id}/versions`),
};

type Decision = { result_id: number; decision: 'confirmed' | 'corrected'; changes?: CriterionChange[] };
type Page<T> = { data: T[]; meta: PageMeta };

export const reviewsApi = {
    queue: (query?: Query) => get<Page<ReviewItem> & { counts: { pending: number; in_progress: number; mine: number } }>('/review-queue', query),
    request: (callId: number, reason: string) => post<ReviewItem>(`/calls/${callId}/reviews`, { reason }),
    assignees: (id: number) => get<{ data: { id: number; name: string }[] }>(`/review-queue/${id}/assignees`),
    assign: (id: number, assigneeId: number, lockVersion: number) => post<ReviewItem>(`/review-queue/${id}/assign`, { assignee_id: assigneeId, lock_version: lockVersion }),
    take: (id: number, lockVersion: number) => post<ReviewItem>(`/review-queue/${id}/take`, { lock_version: lockVersion }),
    resolve: (id: number, lockVersion: number, comment: string, decisions: Decision[]) =>
        post<ReviewItem>(`/review-queue/${id}/resolve`, { lock_version: lockVersion, comment, decisions }),
    cancel: (id: number, lockVersion: number, reason: string) => post<ReviewItem>(`/review-queue/${id}/cancel`, { lock_version: lockVersion, reason }),
    correctTranscript: (callId: number, version: number, reason: string, segments: { id: number; text?: string; speaker_role?: string }[]) =>
        post<{ version: number }>(`/calls/${callId}/transcript/corrections`, { version, reason, segments }),
};

export const appealsApi = {
    list: (query?: Query) => get<Page<AppealItem>>('/appeals', query),
    create: (callId: number, resultId: number, reason: string) => post<AppealItem>(`/calls/${callId}/appeals`, { result_id: resultId, reason }),
    assignees: (id: number) => get<{ data: { id: number; name: string }[] }>(`/appeals/${id}/assignees`),
    assign: (id: number, assigneeId: number, lockVersion: number) => post<AppealItem>(`/appeals/${id}/assign`, { assignee_id: assigneeId, lock_version: lockVersion }),
    take: (id: number, lockVersion: number) => post<AppealItem>(`/appeals/${id}/take`, { lock_version: lockVersion }),
    withdraw: (id: number, lockVersion: number) => post<AppealItem>(`/appeals/${id}/withdraw`, { lock_version: lockVersion }),
    resolve: (id: number, lockVersion: number, decision: 'accepted' | 'rejected', comment: string, changes: CriterionChange[]) =>
        post<AppealItem>(`/appeals/${id}/resolve`, { lock_version: lockVersion, decision, comment, changes }),
};

export const analyticsApi = {
    report: (query?: Query) => get<AnalyticsReport>('/analytics', query),
    exportUrl: (query: Query) => `/api/v1/analytics/export?${new URLSearchParams(Object.entries(clean(query)).map(([k, v]) => [k, String(v)])).toString()}`,
};
