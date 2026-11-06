import assert from 'node:assert/strict';
import { loadTradingServiceClass } from './TradingServiceFactory';

const Service = await loadTradingServiceClass('bitget', 'stockmarket');
const service = new Service('StockMarketSmoke', 1);
assert.equal(service.constructor.name, 'StockMarketTradingService');
console.log('StockMarketFactory: OK');
