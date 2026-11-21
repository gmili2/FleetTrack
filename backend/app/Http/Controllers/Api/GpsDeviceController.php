<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GpsDevice\GpsDeviceRequest;
use App\Models\AuditLog;
use App\Models\DriverShift;
use App\Models\GpsDevice;
use App\Support\GpsConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Cartes GPS : CRUD, camion sur lequel la carte est installée et configuration de chaque carte. */
class GpsDeviceController extends Controller
{
    /** Une carte est « en ligne » si elle a émis dans les 10 dernières minutes. */
    private const ONLINE_MINUTES = 10;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', GpsDevice::class);
        $request->validate(['per_page' => ['nullable', 'integer', 'in:10,20,50,100'], 'tab' => ['nullable', 'in:all,installed,free,inactive,in_service']]);

        $page = $this->filtered($request)->paginate($request->integer('per_page', 20));
        $inService = $this->openShifts();
        $online = now()->subMinutes(self::ONLINE_MINUTES);

        return response()->json([
            'data' => collect($page->items())->map(fn (GpsDevice $d) => $this->row($d, $inService)),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'counts' => [
                    'all' => GpsDevice::count(),
                    'installed' => GpsDevice::whereNotNull('vehicle_id')->count(),
                    'free' => GpsDevice::whereNull('vehicle_id')->where('is_active', true)->count(),
                    'inactive' => GpsDevice::where('is_active', false)->count(),
                    'in_service' => $inService->count(),
                    'online' => GpsDevice::where('last_seen_at', '>=', $online)->count(),
                ],
                'protocols' => GpsConfig::PROTOCOLS,
                'operators' => GpsConfig::OPERATORS,
                'defaults' => GpsConfig::DEFAULTS,
            ],
        ]);
    }

    public function show(GpsDevice $gpsDevice): JsonResponse
    {
        Gate::authorize('view', $gpsDevice);

        return response()->json(['data' => $this->row($gpsDevice->load('vehicle'), $this->openShifts())]);
    }

    public function store(GpsDeviceRequest $request): JsonResponse
    {
        $device = DB::transaction(fn () => $this->save(new GpsDevice, $request->validated()));
        AuditLog::record('created', $device, "Carte GPS créée : {$device->displayName()} ({$device->imei})");

        return response()->json(['data' => $this->row($device, $this->openShifts())], 201);
    }

    public function update(GpsDeviceRequest $request, GpsDevice $gpsDevice): JsonResponse
    {
        $device = DB::transaction(fn () => $this->save($gpsDevice, $request->validated()));
        AuditLog::record('updated', $device, "Carte GPS modifiée : {$device->displayName()}");

        return response()->json(['data' => $this->row($device, $this->openShifts())]);
    }

    public function destroy(GpsDevice $gpsDevice): JsonResponse
    {
        Gate::authorize('delete', $gpsDevice);
        if ($shift = $this->openShifts()->get($gpsDevice->id)) {
            throw ValidationException::withMessages(['device' => "Cette carte est utilisée par {$shift->driver?->full_name} : attendez la fin de son service."]);
        }
        $gpsDevice->delete();
        AuditLog::record('deleted', $gpsDevice, "Carte GPS supprimée : {$gpsDevice->displayName()} ({$gpsDevice->imei})");

        return response()->json(['message' => "Carte {$gpsDevice->displayName()} supprimée."]);
    }

    /** Enregistre la carte ; installer une carte sur un camion en retire l'ancienne carte de ce camion. */
    private function save(GpsDevice $device, array $data): GpsDevice
    {
        $open = $device->exists ? $this->openShifts()->get($device->id) : null;
        $vehicleId = $data['vehicle_id'] ?? null;
        $moves = $device->vehicle_id !== $vehicleId;
        if ($open && ($moves || ! $data['is_active'])) {
            throw ValidationException::withMessages(['vehicle_id' => "Carte en service avec {$open->driver?->full_name} : impossible de la déplacer ou de la désactiver avant la fin du service."]);
        }
        if ($vehicleId && $moves) {
            $other = GpsDevice::where('vehicle_id', $vehicleId)->lockForUpdate()->first();
            if ($other && ($busy = $this->openShifts()->get($other->id))) {
                throw ValidationException::withMessages(['vehicle_id' => "{$busy->vehicle?->name} est en service avec la carte {$other->displayName()}."]);
            }
            $other?->update(['vehicle_id' => null]);
        }

        $device->fill([
            'label' => $data['label'] ?? null, 'imei' => $data['imei'], 'model' => $data['model'] ?? null, 'sim_number' => $data['sim_number'] ?? null,
            'operator' => $data['operator'] ?? null, 'vehicle_id' => $vehicleId, 'is_active' => $data['is_active'], 'notes' => $data['notes'] ?? null,
        ]);
        if (array_key_exists('config', $data) && $data['config'] !== null) {
            $device->config = GpsConfig::merge($data['config']);
        }
        if ($vehicleId && ($moves || ! $device->installed_at)) {
            $device->installed_at = now();
        }
        $device->save();

        return $device->load('vehicle');
    }

    /** Services ouverts, indexés par carte GPS. */
    private function openShifts()
    {
        return DriverShift::with('driver:id,first_name,last_name', 'vehicle:id,name')->whereNull('end_at')->whereNotNull('gps_device_id')
            ->get()->keyBy('gps_device_id');
    }

    private function row(GpsDevice $d, $inService): array
    {
        $shift = $inService->get($d->id);

        return [
            'id' => $d->id, 'label' => $d->label, 'name' => $d->displayName(), 'imei' => $d->imei, 'model' => $d->model,
            'sim_number' => $d->sim_number, 'operator' => $d->operator, 'operator_label' => GpsConfig::OPERATORS[$d->operator] ?? null,
            'is_active' => $d->is_active, 'notes' => $d->notes,
            'vehicle' => $d->vehicle ? ['id' => $d->vehicle->id, 'name' => $d->vehicle->name, 'plate_number' => $d->vehicle->plate_number] : null,
            'installed_at' => $d->installed_at?->toIso8601String(), 'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'online' => $d->last_seen_at !== null && $d->last_seen_at->gte(now()->subMinutes(self::ONLINE_MINUTES)),
            'in_service' => $shift ? ['shift_id' => $shift->id, 'driver' => $shift->driver?->full_name, 'start_at' => $shift->start_at->toIso8601String()] : null,
            'config' => $d->configuration(),
            'protocol_label' => GpsConfig::PROTOCOLS[$d->configuration()['protocol']] ?? null,
        ];
    }

    private function filtered(Request $request): Builder
    {
        $q = trim((string) $request->query('q'));

        return GpsDevice::query()->with('vehicle:id,name,plate_number')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('imei', 'like', "%$q%")->orWhere('label', 'like', "%$q%")
                ->orWhere('sim_number', 'like', "%$q%")->orWhere('model', 'like', "%$q%")
                ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'like', "%$q%")->orWhere('plate_number', 'like', "%$q%"))))
            ->when($request->query('tab'), fn ($b, $tab) => match ($tab) {
                'installed' => $b->whereNotNull('vehicle_id'),
                'free' => $b->whereNull('vehicle_id')->where('is_active', true),
                'inactive' => $b->where('is_active', false),
                'in_service' => $b->whereIn('id', DriverShift::whereNull('end_at')->whereNotNull('gps_device_id')->select('gps_device_id')),
                default => $b,
            })
            ->orderByRaw('label is null')->orderBy('label')->orderBy('imei');
    }
}
