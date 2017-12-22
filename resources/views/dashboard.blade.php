@extends('log-viewer::_template.master')

@section('content')
    <h1 class="page-header">Dashboard</h1>

    <div class="row">
        @if($apps)
        <div class="col-md-9" >
            <lable style="font-size:16px; font-weight: bold;">APPS：</lable>
            <select id="changeApps" style="width: 150px; height: 42px; line-height: 42px; margin:0; padding: 0; ">
                @foreach($apps $value)
                <option value="{{ $value }}" @if($app == $value )selected="selected" @endif>{{ $value }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div class="col-md-3">
            <canvas id="stats-doughnut-chart" height="300"></canvas>
        </div>
        <div class="col-md-9">
            <section class="box-body">
                <div class="row">
                    @foreach($percents as $level => $item)
                        <div class="col-md-4">
                            <div class="info-box level level-{{ $level }} {{ $item['count'] === 0 ? 'level-empty' : '' }}">
                                <span class="info-box-icon">
                                    {!! log_styler()->icon($level) !!}
                                </span>

                                <div class="info-box-content">
                                    <span class="info-box-text">{{ $item['name'] }}</span>
                                    <span class="info-box-number">
                                        {{ $item['count'] }} entries - {!! $item['percent'] !!} %
                                    </span>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: {{ $item['percent'] }}%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        $(function() {
            new Chart($('canvas#stats-doughnut-chart'), {
                type: 'doughnut',
                data: {!! $chartData !!},
                options: {
                    legend: {
                        position: 'bottom'
                    }
                }
            });
            $('#changeApps').change(function(){
                window.location='?app=' + this.value
            });
        });
    </script>
@endsection
