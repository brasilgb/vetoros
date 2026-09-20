<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\Image;
use App\Models\App\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ImageController extends Controller
{
    private const MAX_IMAGES_PER_ORDER = 8;

    private function currentTenantId(): ?int
    {
        return $this->currentUser()?->tenant_id ? (int) $this->currentUser()->tenant_id : null;
    }

    private function currentUser(): ?User
    {
        $user = Auth::user() ?? Auth::guard('sanctum')->user();

        return $user instanceof User ? $user : null;
    }

    private function findOrderByNumber(int $orderNumber): Order
    {
        $query = Order::query()->where('order_number', $orderNumber);
        $user = $this->currentUser();

        if ($user?->isTechnician() && ! $user->canViewAllOrders()) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            });
        }

        return $query->firstOrFail();
    }

    private function orderImagesPath(Order $order): string
    {
        return public_path('storage/orders/'.$order->order_number);
    }

    private function deleteImageFile(Order $order, Image $image): void
    {
        $paths = [
            $this->orderImagesPath($order).DIRECTORY_SEPARATOR.$image->filename,
            public_path('storage/orders/'.$image->order_id).DIRECTORY_SEPARATOR.$image->filename,
        ];

        foreach (array_unique($paths) as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function index(Request $request)
    {
        $query = $request->get('or');
        $order = Order::findOrFail($query);
        $this->authorize('view', $order);

        $images = Image::where('order_id', $query)->get();

        return Inertia::render('app/images/index', [
            'savedimages' => $images,
            'orderid' => $order->id,
            'ordernumber' => $order->order_number,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            [
                'order_id' => ['required', 'exists:orders,id'],
                'images' => ['required', 'array', 'min:1'],
                'images.*' => [
                    'bail',
                    'file',
                    'image',
                    'mimes:jpeg,png,jpg,gif,svg',
                    'max:10240',
                ],
            ],
            [
                'images.*.image' => 'Apenas imagens são permitidas.',
                'images.*.mimes' => 'Formato inválido. Envie apenas JPG, PNG, GIF ou SVG.',
            ]
        );

        $order = Order::findOrFail($validated['order_id']);
        $this->authorize('update', $order);

        /** 🔒 REGRA DE NEGÓCIO (TOTAL POR ORDEM) */
        $existingCount = Image::where('order_id', $validated['order_id'])->count();
        $incomingCount = count($validated['images']);

        if (($existingCount + $incomingCount) > self::MAX_IMAGES_PER_ORDER) {
            throw ValidationException::withMessages([
                'images' => 'Esta ordem pode ter no máximo '.self::MAX_IMAGES_PER_ORDER.' imagens no total.',
            ]);
        }

        $storePath = $this->orderImagesPath($order);

        if (! file_exists($storePath)) {
            mkdir($storePath, 0777, true);
        }

        foreach ($validated['images'] as $imageFile) {
            $filename = uniqid().'.'.$imageFile->getClientOriginalExtension();
            $imageFile->move($storePath, $filename);

            Image::create([
                'order_id' => $validated['order_id'],
                'filename' => $filename,
            ]);
        }

        return redirect()->back()->with('success', 'Imagens enviadas com sucesso!');
    }

    public function destroy(Image $image)
    {
        $order = Order::query()->findOrFail($image->order_id);
        $this->authorize('update', $order);

        $this->deleteImageFile($order, $image);
        $image->delete();

        return redirect()->back()->with('success', 'Imagem excluida com sucesso!');
    }

    // Delete image for id
    public function deleteImageOrder(int $aimage)
    {
        $image = Image::query()->findOrFail($aimage);
        $order = Order::query()->findOrFail($image->order_id);
        $this->authorize('update', $order);

        $this->deleteImageFile($order, $image);
        $image->delete();

        return [
            'success' => true,
            'message' => 'Imagem deletada com sucesso!',
        ];
    }

    public function upload(Request $request)
    {
        $validated = $request->validate([
            'order_number' => ['required_without:order_id', 'integer'],
            'order_id' => ['required_without:order_number', 'integer'],
            'filename' => ['required', 'string'],
        ]);

        $orderNumber = (int) ($validated['order_number'] ?? $validated['order_id']);
        $order = $this->findOrderByNumber($orderNumber);
        $this->authorize('update', $order);

        if (Image::where('order_id', $order->id)->count() >= self::MAX_IMAGES_PER_ORDER) {
            throw ValidationException::withMessages([
                'filename' => 'Esta ordem pode ter no máximo '.self::MAX_IMAGES_PER_ORDER.' imagens no total.',
            ]);
        }

        $image = base64_decode($validated['filename'], true);
        if ($image === false) {
            throw ValidationException::withMessages([
                'filename' => 'Imagem inválida.',
            ]);
        }

        $storePath = $this->orderImagesPath($order);
        if (! file_exists($storePath)) {
            mkdir($storePath, 0777, true);
        }
        $filename = time().rand(1, 50).'.'.'png';
        File::put($storePath.DIRECTORY_SEPARATOR.$filename, $image);
        Image::create([
            'order_id' => $order->id,
            'filename' => $filename,
            'tenant_id' => $this->currentTenantId(),
        ]);

        return [
            'success' => true,
            'message' => 'Imagem salva com sucesso',
        ];
    }

    public function getImages(int $order)
    {
        $order = $this->findOrderByNumber($order);
        $this->authorize('view', $order);

        $images = Image::where('order_id', $order->id)->get();

        return [
            'success' => true,
            'result' => $images,
        ];
    }
}
