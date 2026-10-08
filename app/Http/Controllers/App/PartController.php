<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\PartRequest;
use App\Models\App\Part;
use App\Models\App\PartMovement;
use App\Models\App\PurchaseOrderItem;
use App\Models\App\SaleItem;
use App\Support\Ean13;
use App\Support\Pagination;
use App\Support\PartCostPolicy;
use App\Support\TenantSequence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class PartController extends Controller
{
    private const PART_IMAGES_PATH = 'storage/parts';

    private function currentTenantId(): ?int
    {
        return Auth::user()?->tenant_id ? (int) Auth::user()->tenant_id : null;
    }

    public function getPartsForPartNumber(Request $request)
    {
        Gate::authorize('parts.access');

        $referenceNumber = trim((string) $request->reference_number);

        $parts = Part::query()
            ->where(function ($query) use ($referenceNumber) {
                $this->applyBarcodeSearch($query, $referenceNumber);
            })
            ->first();

        return response()->json([
            'success' => true,
            'parts' => $parts,
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        Gate::authorize('parts.access');

        $search = $request->search;
        $filter = $request->get('filter');

        $query = Part::orderBy('id', 'DESC');

        if ($filter === 'low_stock') {
            $query->whereColumn('quantity', '<=', 'minimum_stock_level');
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhere('part_number', 'like', "%{$search}%");

                $this->applyBarcodeSearch($q, $search);
            });
        }

        $parts = $query->paginate(Pagination::perPage())->withQueryString();

        return Inertia::render('app/parts/index', [
            'parts' => $parts,
            'search' => $search,
            'filter' => $filter,
        ]);
    }

    /**
     * Busca leve usada por selects com autocomplete (PDV, ordens, orçamentos).
     * Retorna só os campos necessários pra exibir/selecionar, sem carregar a tabela inteira.
     */
    public function search(Request $request)
    {
        Gate::authorize('parts.access');

        $search = trim((string) $request->get('q', ''));
        $sellableOnly = $request->boolean('sellable');

        $parts = Part::query()
            ->select(['id', 'name', 'quantity', 'sale_price', 'part_number', 'reference_number'])
            ->when($sellableOnly, fn ($query) => $query->where('is_sellable', true))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('reference_number', 'like', '%'.$search.'%')
                        ->orWhere('part_number', 'like', '%'.$search.'%');

                    $this->applyBarcodeSearch($inner, $search);
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($parts);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        Gate::authorize('parts.access');

        $categories = Part::distinct()->pluck('category');
        $manufacturers = Part::distinct()->pluck('manufacturer');

        return Inertia::render('app/parts/create-part', [
            'categories' => $categories,
            'manufacturers' => $manufacturers,
            'fiscalNfeEnabled' => $this->fiscalNfeEnabled(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PartRequest $request): RedirectResponse
    {
        Gate::authorize('parts.access');

        $data = $request->validated();
        $image = $this->storePartImage($request);

        DB::transaction(function () use ($data, $image) {
            $part = Part::firstOrCreate(
                [
                    'tenant_id' => $this->currentTenantId(),
                    'reference_number' => $data['reference_number'],
                ],
                [
                    'part_number' => TenantSequence::next(Part::class, 'part_number', $this->currentTenantId()),
                    'type' => $data['type'],
                    'is_sellable' => $data['is_sellable'],
                    'category' => $data['category'],
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'image' => $image,
                    'ncm' => $data['ncm'] ?? null,
                    'cfop' => $data['cfop'] ?? null,
                    'manufacturer' => $data['manufacturer'],
                    'model_compatibility' => $data['model_compatibility'] ?? null,
                    'cost_price' => $data['cost_price'],
                    'sale_price' => $data['sale_price'],
                    'quantity' => 0, // Começa com 0, será incrementado abaixo
                    'minimum_stock_level' => $data['minimum_stock_level'],
                    'location' => $data['location'] ?? null,
                    'status' => $data['status'],
                ]
            );

            if ($image && ! $part->wasRecentlyCreated) {
                $this->deletePartImage($part->image);
                $part->update(['image' => $image]);
            }

            // O `update` com `increment` é seguro em concorrência
            $part->increment('quantity', $data['quantity']);

            // Registrar o movimento de entrada
            PartMovement::create([
                'part_id' => $part->id,
                'user_id' => Auth::id(),
                'movement_type' => PartMovement::TYPE_STOCK_IN,
                'quantity' => $data['quantity'],
                ...PartCostPolicy::currentMovementCost($part, (int) $data['quantity']),
                'reason' => 'Cadastro inicial',
            ]);
        });

        return redirect()->route('app.parts.index')->with('success', 'Peça cadastrada com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Part $part, Request $request)
    {
        Gate::authorize('parts.access');

        $categories = Part::distinct()->pluck('category');
        $manufacturers = Part::distinct()->pluck('manufacturer');

        return Inertia::render('app/parts/edit-part', [
            'parts' => $part,
            'categories' => $categories,
            'manufacturers' => $manufacturers,
            'fiscalNfeEnabled' => $this->fiscalNfeEnabled(),
            'page' => $request->page,
            'search' => $request->search,
        ]);
    }

    public function printLabel(Part $part)
    {
        Gate::authorize('parts.access');

        return Inertia::render('app/parts/print-label', [
            'part' => $part,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Part $part, Request $request)
    {
        Gate::authorize('parts.access');

        return Redirect::route('app.parts.show', [
            'part' => $part->id,
            'page' => $request->page,
            'search' => $request->search,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PartRequest $request, Part $part): RedirectResponse
    {
        Gate::authorize('parts.access');

        $data = $request->validated();
        $image = $this->storePartImage($request);

        DB::transaction(function () use ($part, $data, $image) {
            $oldQuantity = $part->quantity;
            if ($image) {
                $this->deletePartImage($part->image);
                $data['image'] = $image;
            } else {
                unset($data['image']);
            }

            $part->update($data);
            $newQuantity = $part->quantity;

            $quantityDiff = $newQuantity - $oldQuantity;

            if ($quantityDiff != 0) {
                PartMovement::create([
                    'part_id' => $part->id,
                    'user_id' => Auth::id(),
                    'movement_type' => PartMovement::TYPE_ADJUSTMENT,
                    'quantity' => abs($quantityDiff),
                    ...PartCostPolicy::currentMovementCost($part, abs($quantityDiff)),
                    'reason' => 'Ajuste de estoque',
                ]);
            }
        });

        return redirect()->route('app.parts.show', ['part' => $part->id])->with('success', 'Peça alterada com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Part $part)
    {
        Gate::authorize('parts.access');

        $hasUsage = $part->orders()->exists()
            || SaleItem::where('part_id', $part->id)->exists()
            || PurchaseOrderItem::where('part_id', $part->id)->exists();

        if ($hasUsage) {
            return back()->with(
                'error',
                'Esta peça já foi usada em ordens de serviço, vendas ou compras e não pode ser excluída. Desative-a em vez de excluir.'
            );
        }

        DB::transaction(function () use ($part) {
            PartMovement::create([
                'part_id' => $part->id,
                'user_id' => Auth::id(),
                'movement_type' => PartMovement::TYPE_ADJUSTMENT,
                'quantity' => $part->quantity,
                ...PartCostPolicy::currentMovementCost($part, (int) $part->quantity),
                'reason' => 'Exclusão de peça',
            ]);

            $this->deletePartImage($part->image);
            $part->delete();
        });

        return redirect()->route('app.parts.index')->with('success', 'Peça excluida com sucesso!');
    }

    private function applyBarcodeSearch($query, string $search): void
    {
        $barcode = preg_replace('/\D+/', '', $search) ?: null;
        $ean = Ean13::normalize($search);

        $query->orWhere('reference_number', $search)
            ->orWhere('part_number', $search);

        if ($ean) {
            $query->orWhere('reference_number', $ean);
        }

        if ($barcode) {
            $query->orWhere('reference_number', 'like', "%{$barcode}%")
                ->orWhere('part_number', 'like', "%{$barcode}%");
        }
    }

    private function fiscalNfeEnabled(): bool
    {
        return false;
    }

    private function storePartImage(PartRequest $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $storePath = public_path(self::PART_IMAGES_PATH);
        if (! File::exists($storePath)) {
            File::makeDirectory($storePath, 0777, true);
        }

        $file = $request->file('image');
        $filename = uniqid('part_', true).'.'.$file->getClientOriginalExtension();
        $file->move($storePath, $filename);

        return $filename;
    }

    private function deletePartImage(?string $filename): void
    {
        if (! $filename) {
            return;
        }

        $path = public_path(self::PART_IMAGES_PATH.DIRECTORY_SEPARATOR.$filename);
        if (File::exists($path)) {
            File::delete($path);
        }
    }
}
