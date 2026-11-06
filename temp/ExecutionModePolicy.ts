export type ExecutionMode = 'maker' | 'taker' | 'market';

export function normalizeInstitutionalExecutionMode(
    modeRaw: unknown,
    fallback: ExecutionMode = 'maker',
): ExecutionMode {
    const mode = String(modeRaw || fallback).toLowerCase();
    if (mode === 'market' || mode === 'taker') return 'market';
    return 'maker';
}

/** Fechos de risco/cleanup — não inclui hedge_ (usa hedgeCloseExecutionMode). */
export function isInstitutionalEmergencyCloseReason(reasonRaw: unknown): boolean {
    const reason = String(reasonRaw || '').toLowerCase();
    if (!reason) return false;
    if (reason.includes('risk') || reason.includes('critical')) return true;
    if (reason.includes('max_hold') || reason.includes('max_adverse')) return true;
    if (reason.includes('forced_end_of_backtest')) return true;
    if (reason.includes('pairing_take_fallback')) return true;
    return false;
}

export function isInstitutionalHedgeCloseReason(
    reasonRaw: unknown,
    position?: { qgHedge?: boolean | null } | null,
): boolean {
    const reason = String(reasonRaw || '').toLowerCase();
    return reason.startsWith('hedge_') || !!position?.qgHedge;
}

export function isInstitutionalHedgeOpenReason(reasonRaw: unknown, isHedgeFlag = false): boolean {
    return isHedgeFlag || String(reasonRaw || '').toLowerCase().startsWith('hedge_');
}

export function resolveInstitutionalOpenExecutionMode(
    config: Record<string, any> | null | undefined,
    reason: string,
    isHedge: boolean,
    rsiOverlayOpenMode?: ExecutionMode,
    isRsiOverlay = false,
): ExecutionMode {
    if (isInstitutionalHedgeOpenReason(reason, isHedge)) {
        return normalizeInstitutionalExecutionMode(
            config?.hedgeOpenExecutionMode
                || config?.openExecutionMode
                || config?.runtimeOpenExecutionMode
                || config?.quantumOpenExecutionMode,
            'maker',
        );
    }
    if (isRsiOverlay) {
        return normalizeInstitutionalExecutionMode(rsiOverlayOpenMode, 'maker');
    }
    return normalizeInstitutionalExecutionMode(
        config?.openExecutionMode
            || config?.quantumOpenExecutionMode
            || config?.runtimeOpenExecutionMode,
        'maker',
    );
}

export function resolveInstitutionalCloseExecutionMode(
    config: Record<string, any> | null | undefined,
    reason: string,
    forceLoss: boolean,
    position: { qgHedge?: boolean | null; closeExecutionMode?: ExecutionMode | string | null } | null | undefined,
    overrideMode?: string,
    rsiOverlayCloseMode?: ExecutionMode,
    isRsiOverlay = false,
): ExecutionMode {
    if (overrideMode) {
        return normalizeInstitutionalExecutionMode(overrideMode, 'maker');
    }

    if (forceLoss || isInstitutionalEmergencyCloseReason(reason)) {
        return normalizeInstitutionalExecutionMode(config?.riskEmergencyCloseMode, 'market');
    }

    if (isInstitutionalHedgeCloseReason(reason, position)) {
        return normalizeInstitutionalExecutionMode(
            config?.hedgeCloseExecutionMode
                || config?.closeExecutionMode
                || config?.runtimeCloseExecutionMode
                || config?.quantumCloseExecutionMode
                || position?.closeExecutionMode,
            'maker',
        );
    }

    if (isRsiOverlay) {
        return normalizeInstitutionalExecutionMode(rsiOverlayCloseMode, 'maker');
    }

    return normalizeInstitutionalExecutionMode(
        config?.closeExecutionMode
            || config?.quantumCloseExecutionMode
            || config?.runtimeCloseExecutionMode
            || position?.closeExecutionMode,
        'maker',
    );
}

export function resolveInstitutionalPlannedHedgeCloseMode(
    config: Record<string, any> | null | undefined,
): ExecutionMode {
    return normalizeInstitutionalExecutionMode(
        config?.hedgeCloseExecutionMode || config?.closeExecutionMode || config?.runtimeCloseExecutionMode,
        'maker',
    );
}
