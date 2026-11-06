@extends('partials.layouts.master')

@section('title', 'Echart Chart | Urbix Admin & Dashboards Template')
@section('title-sub', 'Base UI')
@section('pagetitle', 'Echart Chart')

@section('content')

      <div class="row">
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Basic Line Chart</h6>
            </div>
            <div class="card-body">
              <div id="BasicLineChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Smoothed Line Chart</h6>
            </div>
            <div class="card-body">
              <div id="SmoothedLineChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Stacked Line Chart</h6>
            </div>
            <div class="card-body">
              <div id="StackedLineChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Step Line Chart</h6>
            </div>
            <div class="card-body">
              <div id="StepLine" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Basic Bar Chart</h6>
            </div>
            <div class="card-body">
              <div id="BasicBarChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Dataset Chart</h6>
            </div>
            <div class="card-body">
              <div id="DatasetChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Basic Scatter Chart</h6>
            </div>
            <div class="card-body">
              <div id="BasicScatterChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Basic Radar Chart</h6>
            </div>
            <div class="card-body">
              <div id="BasicRadarChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Nightingale Chart</h6>
            </div>
            <div class="card-body">
              <div id="NightingaleChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Basic Candlestick Chart</h6>
            </div>
            <div class="card-body">
              <div id="BasicCandlestickChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Graph on Cartesian Chart</h6>
            </div>
            <div class="card-body">
              <div id="GraphonCartesianChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Simple Gauge Chart</h6>
            </div>
            <div class="card-body">
              <div id="SimpleGaugeChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Funnel Compare Chart</h6>
            </div>
            <div class="card-body">
              <div id="CustomizedFunnelChart" class="h-320px"></div>
            </div>
          </div>
        </div>
        <div class="col-12 col-xl-6">
          <div class="card">
            <div class="card-header">
              <h6 class="card-title mb-0 fw-semibold">Basic Sankey Chart</h6>
            </div>
            <div class="card-body">
              <div id="BasicSankeyChart" class="h-320px"></div>
            </div>
          </div>
        </div>

      </div><!--End row-->
    </div><!--End container-fluid-->
  </main><!--End app-wrapper-->

@endsection

@section('js')

<script src="assets/libs/echarts/echarts.min.js"></script>

<script src="assets/js/chart/echart.init.js"></script>

<script src="assets/js/app.js"></script>
@endsection