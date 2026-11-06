import MarginTradingService from '../ExecuteOperation/MarginTrade/MarginTradingService';
import QuantumTradingService from '../ExecuteOperation/QuantumTrade/QuantumTradingService';
import MarketKingTradingService from '../ExecuteOperation/MarketKingTrade/MarketKingTradingService';
import GoldTeamTradingService from '../ExecuteOperation/GoldTeamTrade/GoldTeamTradingService';
import StockMarketTradingService from '../ExecuteOperation/StockMarketTrade/StockMarketTradingService';
import type { ExchangeSignalClient } from '../lib/Api/ExchangeWsClient';
import { BatchOperations } from '../lib/BatchOperations';
import {
    normalizeConfiguredTradeMode,
} from '../ExecuteOperation/TradeModeRouting';

const SUPPORTED_TRADING_MODES = ['margintrade', 'quantum', 'marketking', 'goldteam', 'stockmarket'] as const;
export type TradingMode = (typeof SUPPORTED_TRADING_MODES)[number];

const DEFAULT_TRADING_MODE: TradingMode = 'marketking';
const MARGINTRADE_MODE: TradingMode = 'margintrade';
const QUANTUM_MODE: TradingMode = 'quantum';
const MARKETKING_MODE: TradingMode = 'marketking';
const GOLDTEAM_MODE: TradingMode = 'goldteam';
const STOCKMARKET_MODE: TradingMode = 'stockmarket';

export class TradingFactory {
    static createTradingService(
        botName: string,
        maxOperations: number,
        tradeType: TradingMode,
        botId?: number,
        dbConfig?: any,
        signalClient?: ExchangeSignalClient,
        batchOps?: BatchOperations
    ): any {
        if (tradeType === MARGINTRADE_MODE) {
            return new MarginTradingService(botName, maxOperations, botId, dbConfig, signalClient, batchOps);
        }

        if (tradeType === QUANTUM_MODE) {
            return new QuantumTradingService(botName, maxOperations, botId, dbConfig, signalClient, batchOps);
        }

        if (tradeType === MARKETKING_MODE) {
            return new MarketKingTradingService(botName, maxOperations, botId, dbConfig, signalClient, batchOps);
        }

        if (tradeType === GOLDTEAM_MODE) {
            return new GoldTeamTradingService(botName, maxOperations, botId, dbConfig, signalClient, batchOps);
        }

        if (tradeType === STOCKMARKET_MODE) {
            return new StockMarketTradingService(botName, maxOperations, botId, dbConfig, signalClient, batchOps);
        }

        return new MarketKingTradingService(botName, maxOperations, botId, dbConfig, signalClient, batchOps);
    }

    static normalizeTradeType(tradeType: string | undefined): TradingMode {
        const normalized = normalizeConfiguredTradeMode(tradeType ?? DEFAULT_TRADING_MODE);
        return SUPPORTED_TRADING_MODES.includes(normalized as TradingMode)
            ? (normalized as TradingMode)
            : DEFAULT_TRADING_MODE;
    }

    static detectTradeType(config: any): TradingMode {
        const explicitTradeMode = String(config?.tradeMode ?? config?.trade_mode ?? '').trim();
        if (explicitTradeMode) {
            const modeFromTradeMode = normalizeConfiguredTradeMode(explicitTradeMode);
            if (modeFromTradeMode === MARGINTRADE_MODE) {
                return MARGINTRADE_MODE;
            }
            if (modeFromTradeMode === QUANTUM_MODE) {
                return QUANTUM_MODE;
            }
            if (modeFromTradeMode === MARKETKING_MODE) {
                return MARKETKING_MODE;
            }
            if (modeFromTradeMode === GOLDTEAM_MODE) {
                return GOLDTEAM_MODE;
            }
            if (modeFromTradeMode === STOCKMARKET_MODE) {
                return STOCKMARKET_MODE;
            }
        }

        const strategy = String(config?.strategy || config?.strategyMode || '').toLowerCase();
        if (strategy.includes('margintrade')) {
            return MARGINTRADE_MODE;
        }
        if (strategy.includes('quantum')) {
            return QUANTUM_MODE;
        }
        if (strategy.includes('marketking') || strategy.includes('institutional_hybrid') || strategy === 'institutional' || strategy === 'hybrid') {
            return MARKETKING_MODE;
        }
        if (strategy.includes('goldteam') || strategy.includes('turtle') || strategy.includes('fimathe')) {
            return GOLDTEAM_MODE;
        }
        if (strategy.includes('stockmarket') || strategy.includes('tradfi') || strategy.includes('stock')) {
            return STOCKMARKET_MODE;
        }

        if (config.leverage && config.leverage > 1) {
            return MARKETKING_MODE;
        }

        if (config.marginMode && (config.marginMode === 'cross' || config.marginMode === 'isolated')) {
            return MARKETKING_MODE;
        }

        return DEFAULT_TRADING_MODE;
    }

    static validateConfigForMode(config: any, mode: TradingMode): { valid: boolean; errors: string[] } {
        const errors: string[] = [];

        if (!config.botId || config.botId <= 0) {
            errors.push('Bot ID invalido');
        }

        if (!config.botName || config.botName.trim() === '') {
            errors.push('Nome do bot nao definido');
        }

        if (!config.market || config.market.trim() === '') {
            errors.push('Market (pair) nao definido');
        }

        if (!config.pair1 || !config.pair2) {
            errors.push('Par de trading (pair1/pair2) nao definido');
        }

        if (config.amount === undefined || config.amount <= 0) {
            errors.push('Valor de operacao (amount) invalido');
        }

        if (!config.opType || !['demo', 'real'].includes(config.opType.toLowerCase())) {
            errors.push('Tipo de operacao (opType) invalido: use "demo" ou "real"');
        }

        if (mode === MARGINTRADE_MODE || mode === QUANTUM_MODE || mode === MARKETKING_MODE || mode === GOLDTEAM_MODE || mode === STOCKMARKET_MODE) {
            const maxLeverage = mode === STOCKMARKET_MODE ? 5 : 100;
            if (!config.leverage || config.leverage < 1 || config.leverage > maxLeverage) {
                errors.push(`Leverage nao definido ou invalido (1-${maxLeverage}x)`);
            }

            if (!config.marginMode || !['cross', 'isolated'].includes(config.marginMode)) {
                errors.push('Modo de margem nao especificado (cross ou isolated)');
            }
        }

        if (mode === MARGINTRADE_MODE || mode === QUANTUM_MODE || mode === MARKETKING_MODE) {
            const stopLossRatio = Number(config.stopLossRatio);
            const allowZeroStopLoss = mode === QUANTUM_MODE;
            if (
                (!allowZeroStopLoss && (!Number.isFinite(stopLossRatio) || stopLossRatio <= 0 || stopLossRatio >= 1))
                || (allowZeroStopLoss && (Number.isFinite(stopLossRatio) ? stopLossRatio < 0 || stopLossRatio >= 1 : false))
            ) {
                errors.push('Stop Loss Ratio nao definido ou invalido (0-1)');
            }

            if (!config.takeProfitRatio || config.takeProfitRatio <= 0 || config.takeProfitRatio >= 1) {
                errors.push('Take Profit Ratio nao definido ou invalido (0-1)');
            }

            if (!config.rsiBuy || config.rsiBuy <= 0 || config.rsiBuy > 100) {
                errors.push('RSI Buy nao configurado para Margin Trading');
            }

            if (!config.rsiSell || config.rsiSell <= 0 || config.rsiSell > 100) {
                errors.push('RSI Sell nao configurado para Margin Trading');
            }
        }

        return {
            valid: errors.length === 0,
            errors,
        };
    }

    static buildAndValidate(
        config: any,
        tradeType: TradingMode,
        botId?: number,
        dbConfig?: any
    ): {
        success: boolean;
        service?: MarginTradingService | QuantumTradingService | MarketKingTradingService | GoldTeamTradingService;
        errors: string[];
        warnings: string[];
    } {
        const warnings: string[] = [];

        const validation = this.validateConfigForMode(config, tradeType);
        if (!validation.valid) {
            return {
                success: false,
                errors: validation.errors,
                warnings: [],
            };
        }

        if ((tradeType === MARGINTRADE_MODE || tradeType === QUANTUM_MODE || tradeType === MARKETKING_MODE || tradeType === GOLDTEAM_MODE || tradeType === STOCKMARKET_MODE) && config.leverage > 10) {
            warnings.push(`Leverage muito alto (${config.leverage}x): aumenta risco de liquidacao`);
        }

        if (config.amount < 10) {
            warnings.push(`Valor de operacao muito baixo ($${config.amount}): pode haver restricoes de trading`);
        }

        const service = this.createTradingService(
            config.botName,
            config.maxOperations,
            tradeType,
            botId ?? config.botId,
            dbConfig ?? config.dbConfig,
            config.signalClient ?? config.wsClient,
            config.batchOps
        );

        return {
            success: true,
            service,
            errors: [],
            warnings,
        };
    }
}

export default TradingFactory;
