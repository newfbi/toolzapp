import path from 'path';

export async function loadTradingServiceClass(exchange: string, tradeMode?: string): Promise<any> {
    const normalizedMode = String(tradeMode || '').trim().toLowerCase();
    if (normalizedMode.includes('stockmarket') || normalizedMode.includes('stock_market') || normalizedMode.includes('tradfi')) {
        const stockCandidates = [
            path.resolve(process.cwd(), 'resources', 'js', 'BotOperations', 'Crypto', 'ExecuteOperation', 'StockMarketTrade', 'StockMarketTradingService.ts'),
            path.resolve(process.cwd(), 'resources', 'js', 'BotOperations', 'Crypto', 'ExecuteOperation', 'StockMarketTrade', 'StockMarketTradingService.js'),
        ];
        const { pathToFileURL } = await import('url');
        for (const candidate of stockCandidates) {
            try {
                const moduleRef: any = await import(pathToFileURL(candidate).href);
                return moduleRef.StockMarketTradingService || moduleRef.default || moduleRef;
            } catch (_) {
                // try compiled/source candidate
            }
        }
        throw new Error(`StockMarketTradingService not found for exchange '${exchange}'`);
    }
    const candidates = [
        path.resolve(process.cwd(), 'resources', 'js', 'BotOperations', 'Crypto', 'scripts', 'BackTestMarketKing', 'MockMarketKingTradingService.ts'),
        path.resolve(process.cwd(), 'resources', 'js', 'BotOperations', 'Crypto', 'scripts', 'BackTestMarketKing', 'MockMarketKingTradingService.js'),
    ];

    const { pathToFileURL } = await import('url');

    let lastError: any = null;
    for (const candidate of candidates) {
        try {
            const moduleRef: any = await import(pathToFileURL(candidate).href);
            return moduleRef.MockMarketKingTradingService || moduleRef.default || moduleRef;
        } catch (error) {
            lastError = error;
        }
    }

    const details = lastError && lastError.message ? lastError.message : 'unknown';
    throw new Error(`MockMarketKingTradingService not found for exchange '${exchange}': ${details}`);
}

export default loadTradingServiceClass;
