<?php namespace Arcanedev\LogViewer\Http\Controllers;

use Arcanedev\LogViewer\Contracts\LogViewer as LogViewerContract;
use Arcanedev\LogViewer\Entities\LogEntry;
use Arcanedev\LogViewer\Exceptions\LogNotFoundException;
use Arcanedev\LogViewer\Tables\StatsTable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Class     LogViewerController
 *
 * @package  LogViewer\Http\Controllers
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class LogViewerController extends Controller
{
    /* -----------------------------------------------------------------
     |  Properties
     | -----------------------------------------------------------------
     */

    /**
     * The log viewer instance
     *
     * @var \Arcanedev\LogViewer\Contracts\LogViewer
     */
    protected $logViewer;

    /** @var int */
    protected $perPage = 30;

    /** @var string */
    protected $showRoute = 'log-viewer::logs.show';

    protected $apps = [];

    /* -----------------------------------------------------------------
     |  Constructor
     | -----------------------------------------------------------------
     */

    /**
     * LogViewerController constructor.
     *
     * @param  \Arcanedev\LogViewer\Contracts\LogViewer  $logViewer
     */
    public function __construct(LogViewerContract $logViewer)
    {
        $this->logViewer = $logViewer;
        $this->perPage = config('log-viewer.per-page', $this->perPage);
        $this->apps = config('log-viewer.apps', []);
    }

    /* -----------------------------------------------------------------
     |  Main Methods
     | -----------------------------------------------------------------
     */

    /**
     * Show the dashboard.
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $app = $request->get('app');
        $apps = $this->apps;
        if ($apps) {
            $app = ($app && in_array($app, $this->apps)) ? $app : $this->apps[0];
        } else {
            $app = config('log-viewer.defaultApp', '');
        }
        $stats = $this->logViewer->setPath(config('log-viewer.storage-path') . '/' . $app)->statsTable();

        $chartData = $this->prepareChartData($stats);
        $percents  = $this->calcPercentages($stats->footer(), $stats->header());
        return $this->view('dashboard', compact('chartData', 'percents', 'app', 'apps'));
    }

    /**
     * List all logs.
     *
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\View\View
     */
    public function listLogs(Request $request)
    {
        $app = $request->get('app');
        $apps = $this->apps;
        if ($apps) {
            $app = ($app && in_array($app, $this->apps)) ? $app : $this->apps[0];
        } else {
            $app = config('log-viewer.defaultApp', '');
        }
        $stats   = $this->logViewer->setPath(config('log-viewer.storage-path') . '/' . $app)->statsTable();
        $headers = $stats->header();
        $rows    = $this->paginate($stats->rows(), $request);

        return $this->view('logs', compact('headers', 'rows', 'footer', 'app', 'apps'));
    }

    /**
     * Show the log.
     *
     * @param  string  $date
     *
     * @return \Illuminate\View\View
     */
    public function show($date, Request $request)
    {
        $app = $request->get('app');
        $apps = $this->apps;
        if ($apps) {
            $app = ($app && in_array($app, $this->apps)) ? $app : $this->apps[0];
        } else {
            $app = config('log-viewer.defaultApp', '');
        }
        $log     = $this->getLogOrFail($date, $app);
        $levels  = $this->logViewer->levelsNames();
        $entries = $log->entries($level = 'all')->paginate($this->perPage);

        return $this->view('show', compact('log', 'levels', 'level', 'search', 'entries', 'app', 'apps'));
    }

    /**
     * Filter the log entries by level.
     *
     * @param  string  $date
     * @param  string  $level
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function showByLevel($date, $level, Request $request)
    {
        $app = $request->get('app');
        $apps = $this->apps;
        if ($apps) {
            $app = ($app && in_array($app, $this->apps)) ? $app : $this->apps[0];
        } else {
            $app = config('log-viewer.defaultApp', '');
        }
        $log = $this->getLogOrFail($date, $app);

        if ($level === 'all')
            return redirect()->route($this->showRoute, [$date, 'app' => $app]);

        $levels  = $this->logViewer->setPath(config('log-viewer.storage-path') . '/' . $app)->levelsNames();
        $entries = $this->logViewer->setPath(config('log-viewer.storage-path') . '/' . $app)->entries($date, $level)->paginate($this->perPage);

        return $this->view('show', compact('log', 'levels', 'level', 'search', 'entries', 'app', 'apps'));
    }

    /**
     * Show the log with the search query.
     *
     * @param  string                    $date
     * @param  string                    $level
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\View\View
     */
    public function search($date, $level = 'all', Request $request) {
        $app = $request->get('app');
        $apps = $this->apps;
        if ($apps) {
            $app = ($app && in_array($app, $this->apps)) ? $app : $this->apps[0];
        } else {
            $app = config('log-viewer.defaultApp', '');
        }
        $log   = $this->getLogOrFail($date, $app);

        if (is_null($query = $request->get('query')))
            return redirect()->route('log-viewer::logs.show', [$date, 'app' => $app]);

        $levels  = $this->logViewer->setPath(config('log-viewer.storage-path') . '/' . $app)->levelsNames();
        $entries = $log->entries($level)->filter(function (LogEntry $value) use ($query) {
            return Str::contains($value->header, $query);
        })->paginate($this->perPage);

        return $this->view('show', compact('log', 'levels', 'level', 'query', 'entries', 'app', 'apps'));
    }

    /**
     * Download the log
     *
     * @param  string  $date
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function download($date, Request $request)
    {
        $app = $request->get('app');
        $apps = $this->apps;
        if ($apps) {
            $app = ($app && in_array($app, $this->apps)) ? $app : $this->apps[0];
        } else {
            $app = config('log-viewer.defaultApp', '');
        }
        return $this->logViewer->setPath(config('log-viewer.storage-path') . '/' . $app)->download($date);
    }

    /**
     * Delete a log.
     *
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(Request $request)
    {
        $app = $request->get('app');
        $apps = $this->apps;
        if ($apps) {
            $app = ($app && in_array($app, $this->apps)) ? $app : $this->apps[0];
        } else {
            $app = config('log-viewer.defaultApp', '');
        }
        if ( ! $request->ajax())
            abort(405, 'Method Not Allowed');

        $date = $request->get('date');

        return response()->json([
            'result' => $this->logViewer->setPath(config('log-viewer.storage-path') . '/' . $app)->delete($date) ? 'success' : 'error'
        ]);
    }

    /* -----------------------------------------------------------------
     |  Other Methods
     | -----------------------------------------------------------------
     */

    /**
     * Get the evaluated view contents for the given view.
     *
     * @param  string  $view
     * @param  array   $data
     * @param  array   $mergeData
     *
     * @return \Illuminate\View\View
     */
    protected function view($view, $data = [], $mergeData = [])
    {
        return view('log-viewer::'.$view, $data, $mergeData);
    }

    /**
     * Paginate logs.
     *
     * @param  array                     $data
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    protected function paginate(array $data, Request $request)
    {
        $data = collect($data);
        $page = $request->get('page', 1);
        $url  = $request->url();

        return new LengthAwarePaginator(
            $data->forPage($page, $this->perPage),
            $data->count(),
            $this->perPage,
            $page,
            compact('url')
        );
    }

    /**
     * Get a log or fail
     *
     * @param  string  $date
     *
     * @return \Arcanedev\LogViewer\Entities\Log|null
     */
    protected function getLogOrFail($date, $app)
    {
        $log = null;

        try {
            $log = $this->logViewer->setPath(config('log-viewer.storage-path') . '/' . $app)->get($date);
        }
        catch (LogNotFoundException $e) {
            abort(404, $e->getMessage());
        }

        return $log;
    }

    /**
     * Prepare chart data.
     *
     * @param  \Arcanedev\LogViewer\Tables\StatsTable  $stats
     *
     * @return string
     */
    protected function prepareChartData(StatsTable $stats)
    {
        $totals = $stats->totals()->all();

        return json_encode([
            'labels'   => Arr::pluck($totals, 'label'),
            'datasets' => [
                [
                    'data'                 => Arr::pluck($totals, 'value'),
                    'backgroundColor'      => Arr::pluck($totals, 'color'),
                    'hoverBackgroundColor' => Arr::pluck($totals, 'highlight'),
                ],
            ],
        ]);
    }

    /**
     * Calculate the percentage.
     *
     * @param  array  $total
     * @param  array  $names
     *
     * @return array
     */
    protected function calcPercentages(array $total, array $names)
    {
        $percents = [];
        $all      = Arr::get($total, 'all');

        foreach ($total as $level => $count) {
            $percents[$level] = [
                'name'    => $names[$level],
                'count'   => $count,
                'percent' => $all ? round(($count / $all) * 100, 2) : 0,
            ];
        }

        return $percents;
    }
}
