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

export interface CodeLabel<T extends string = string> {
    code: T;
    label: string;
}

export type CallStatusCode =
    | 'received' | 'awaiting_audio' | 'queued' | 'preparing' | 'awaiting_transcription' | 'awaiting_evaluation'
    | 'transcribing' | 'classifying' | 'evaluating' | 'completed' | 'failed' | 'excluded';

export interface CallAudio {
    name: string | null;
    format: string;
    size_bytes: number;
    duration_ms: number;
    codec: string | null;
    sample_rate: number | null;
    channels: number | null;
    bit_rate: number | null;
    sha256: string;
}

export interface CallAttempt {
    id: number;
    step: string;
    attempt: number;
    status: 'running' | 'succeeded' | 'failed';
    started_at: string;
    finished_at: string | null;
    error_code: string | null;
    error_message: string | null;
}

export interface Call {
    id: number;
    source?: { code: string; name: string };
    status: CodeLabel<CallStatusCode>;
    status_changed_at: string | null;
    direction: CodeLabel<'incoming' | 'outgoing' | 'unknown'>;
    started_at: string | null;
    started_at_tz: string | null;
    occurred_at: string;
    duration_ms: number | null;
    company_id: number;
    dealership_id: number | null;
    department_id: number | null;
    employee_id: number | null;
    attribution_status: 'resolved' | 'needs_employee';
    org: {
        company: string | null;
        dealership: string | null;
        department: string | null;
        employee: string | null;
        employee_position: string | null;
        captured_at: string | null;
    };
    note: string | null;
    last_error: string | null;
    excluded: { at: string; reason: string | null } | null;
    duplicate_of: { id: number | null } | null;
    uploader?: { id: number; name: string } | null;
    original?: CallAudio | null;
    normalized?: CallAudio | null;
    attempts?: CallAttempt[];
    created_at: string;
    review_required: boolean;
    review_reason: string | null;
    labels: string[];
    priority: 'normal' | 'high' | 'urgent';
    evaluation?: { score: number | null; provisional_score: number | null; business_status: string; critical_violation: boolean } | null;
    can: { update: boolean; reprocess: boolean; exclude: boolean; play: boolean; download: boolean };
}

export interface CallSummary {
    total: number;
    in_progress: number;
    awaiting_transcription: number;
    completed: number;
    failed: number;
    excluded: number;
    needs_employee: number;
    review_required: number;
    awaiting_evaluation: number;
}

export interface UploadSettings {
    formats: string[];
    max_file_size_mb: number;
    max_files_per_batch: number;
    min_duration_seconds: number;
    max_duration_minutes: number;
}

// --- Этап C: провайдеры ИИ, расшифровка, оценка ---

export type ProviderKindCode = 'asr' | 'llm';
export type ProviderRoleCode = 'primary' | 'fallback' | 'inactive';

export interface ProviderConfig {
    id: number;
    kind: ProviderKindCode;
    driver: string;
    external: boolean;
    name: string;
    model: string | null;
    endpoint: string | null;
    settings: Record<string, string | number | boolean | null>;
    role: ProviderRoleCode;
    secret_set: boolean;
    secret_set_at: string | null;
    lock_version: number;
    stats?: {
        calls_30d: number;
        errors_30d: number;
        cost_30d: number | null;
        avg_duration_ms: number | null;
        last_call_at: string | null;
        last_error: { error_message: string; started_at: string } | null;
    };
}

export interface ProviderDriverInfo {
    code: string;
    name: string;
    external: boolean;
    available: boolean;
    reason?: string;
    endpoint?: string | null;
    models?: string[];
    model_required?: boolean;
    secret_fields?: { key: string; label: string }[];
    note?: string | null;
}

export interface ProvidersOverview {
    data: ProviderConfig[];
    drivers: Record<ProviderKindCode, ProviderDriverInfo[]>;
    presets: Record<string, { name: string; endpoint: string; model: string }[]>;
    mock_scenarios: Record<ProviderKindCode, { code: string; name: string }[]>;
    external_processing: { enabled: boolean; reason: string | null; changed_at: string | null; changed_by: number | null };
}

export interface ProviderCallLog {
    id: number;
    provider: string | null;
    kind: ProviderKindCode;
    driver: string;
    model: string | null;
    operation: string;
    call_id: number | null;
    prompt_version: string | null;
    started_at: string;
    duration_ms: number;
    status: 'ok' | 'error';
    error_code: string | null;
    error_message: string | null;
    attempt: number;
    audio_seconds: number | null;
    tokens_in: number | null;
    tokens_out: number | null;
    cost: number | null;
    currency: string;
}

export interface TranscriptSegment {
    id: number;
    start_ms: number;
    end_ms: number;
    speaker: string;
    speaker_role: 'agent' | 'client' | 'unknown';
    text: string;
    confidence: number | null;
}

export interface TranscriptResponse {
    versions: { version: number; source: string; is_synthetic: boolean; created_at: string }[];
    current: { version: number; source: string; is_synthetic: boolean; created_at: string; segments: TranscriptSegment[] } | null;
}

export interface EvidenceItem {
    type: 'quote' | 'time_range' | string;
    segment_id: number | null;
    quote: string | null;
    start_ms: number | null;
    end_ms: number | null;
    speaker: string | null;
    is_valid: boolean;
    issue: string | null;
}

export interface CriterionResultItem {
    id: number;
    title: string;
    weight: number;
    required: boolean;
    critical: boolean;
    status: 'pass' | 'fail' | 'not_applicable' | 'insufficient_data';
    reason: string | null;
    confidence: number | null;
    evidence_valid: boolean;
    issues: string[];
    evidence: EvidenceItem[];
}

export interface EvaluationResultItem {
    id: number;
    role: 'primary' | 'additional';
    checklist: { name: string; version: number; is_demo: boolean };
    score: number | null;
    provisional_score: number | null;
    critical_violation: boolean;
    business_status: 'full_assessment' | 'human_intervention_required';
    review_state: string;
    approval_state: string;
    summary: string | null;
    recommendations: string[];
    risks: string[];
    issues: string[];
    criteria: CriterionResultItem[];
}

export interface AnalysisResponse {
    workflow?: WorkflowTrace | null;
    runs: { id: number; status: string; driver: string; started_at: string }[];
    current: {
        id: number;
        status: 'running' | 'succeeded' | 'failed';
        driver: string;
        prompt_version: string;
        routing: { role: string; checklist: string; version: number; reason: string }[];
        started_at: string;
        finished_at: string | null;
        error_message: string | null;
        synthetic: boolean;
        results: EvaluationResultItem[];
    } | null;
}

export interface WorkflowTrace {
    id: number;
    status: 'running' | 'waiting_analysis' | 'completed' | 'failed';
    name: string | null;
    version: number | null;
    fallback: boolean;
    started_at: string;
    finished_at: string | null;
    error_message: string | null;
    steps: { node_id: string; type: string; label: string; branch: string | null }[];
}

/* Этап D — конструкторы чек-листов и бизнес-процессов. */

export type VersionStatus = 'draft' | 'published' | 'archived';

export interface DesignSummary {
    id: number;
    name: string;
    description: string | null;
    is_archived: boolean;
    company: { id: number; name: string } | null;
    published_version: number | null;
    has_draft: boolean;
}

export interface ChecklistSummary extends DesignSummary {
    code: string;
    is_demo: boolean;
}

export interface ChecklistCriterion {
    id?: number;
    code: string;
    title: string;
    description: string | null;
    ai_hint: string | null;
    weight: number;
    is_required: boolean;
    is_critical: boolean;
    applicability: string | null;
}

export interface ChecklistVersion {
    id: number;
    version: number;
    status: VersionStatus;
    zero_on_critical: boolean;
    notes: string | null;
    lock_version: number;
    published_at: string | null;
    criteria: ChecklistCriterion[];
}

export interface ChecklistDetail extends ChecklistSummary {
    versions: { id: number; version: number; status: VersionStatus; published_at: string | null; criteria_count: number }[];
    selected: ChecklistVersion | null;
    can: { design: boolean; publish: boolean };
}

export interface ChecklistChange {
    code: string;
    change: 'added' | 'removed' | 'changed';
    before: Partial<ChecklistCriterion> | null;
    after: Partial<ChecklistCriterion> | null;
}

export interface ChecklistTestResult {
    synthetic: boolean;
    score: { score: number | null; provisional_score: number | null; business_status: string; critical_violation: boolean };
    summary: string | null;
    criteria: { title: string; status: CriterionResultItem['status']; reason: string | null; evidence_valid: boolean; issues: string[] }[];
}

export interface GraphNode {
    id: string;
    type: string;
    position: { x: number; y: number };
    data: Record<string, unknown>;
}

export interface GraphEdge {
    id: string;
    source: string;
    sourceHandle?: string | null;
    target: string;
    targetHandle?: string | null;
}

export interface WorkflowGraph {
    nodes: GraphNode[];
    edges: GraphEdge[];
}

export interface WorkflowSummary extends DesignSummary {
    trigger: string;
    priority: number;
    updated_at: string | null;
}

export interface WorkflowVersion {
    id: number;
    version: number;
    status: VersionStatus;
    graph: WorkflowGraph;
    lock_version: number;
    published_at: string | null;
}

export interface WorkflowDetail extends WorkflowSummary {
    versions: { id: number; version: number; status: VersionStatus; published_at: string | null }[];
    selected: WorkflowVersion | null;
    can: { design: boolean; publish: boolean };
}

export type FieldType = 'enum' | 'org' | 'number' | 'bool';

export interface CatalogField {
    label: string;
    type: FieldType;
    phase: 'before_analysis' | 'after_analysis';
    values?: Record<string, string>;
    org?: 'company' | 'dealership' | 'department';
}

export interface WorkflowCatalog {
    node_types: Record<string, { label: string; group: string; available: boolean; description: string }>;
    triggers: Record<string, { label: string; available: boolean }>;
    priorities: Record<string, string>;
    fields: Record<string, CatalogField>;
    operators: Record<FieldType, string[]>;
    operator_labels: Record<string, string>;
    checklists: { id: number; name: string; is_demo: boolean; published_version: number | null }[];
    org: Record<'company' | 'dealership' | 'department', { id: number; name: string }[]>;
}

export interface GraphError {
    node_id: string | null;
    message: string;
}

export interface SimulationResult {
    errors: GraphError[];
    trace: { node_id: string; node_type: string; branch: string | null; output: Record<string, unknown> }[];
    plan: {
        primary_checklist_id: number | null;
        additional_checklist_ids: number[];
        analysis_requested: boolean;
        review_reasons: string[];
        labels: string[];
        priority: string | null;
    };
    context: Record<string, unknown>;
    finished: boolean;
    error: string | null;
}
