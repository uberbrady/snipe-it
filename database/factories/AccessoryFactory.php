<?php

namespace Database\Factories;

use App\Models\Accessory;
use App\Models\Actionlog;
use App\Models\Category;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessoryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Accessory::class;

    public static int $quantity = 1;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => sprintf(
                '%s %s',
                $this->faker->randomElement(['Bluetooth', 'Wired']),
                $this->faker->randomElement(['Keyboard', 'Wired'])
            ),
            'created_by' => User::factory()->superuser(),
            'category_id' => Category::factory()->forAccessories(),
            'model_number' => $this->faker->numberBetween(1000000, 50000000),
            'location_id' => Location::factory(),
        ];
    }

    public function configure(): static
    {
        \Log::error("Main 'configure()' method for after-creation is getting ready to fire...");
        return $this->afterCreating(function (Accessory $accessory) {
            Actionlog::factory()->state([
                'quantity'  => self::$quantity,
                'item_id'   => $accessory->id,
                'item_type' => Accessory::class,
            ])->create();
        });
    }


    public function appleBtKeyboard()
    {
        self::$quantity = 10;
        return $this->state(function () {
            return [
                'name' => 'Bluetooth Keyboard',
                'image' => 'bluetooth.jpg',
                'category_id' => function () {
                    return Category::where('name', 'Keyboards')->first() ?? Category::factory()->accessoryKeyboardCategory();
                },
                'manufacturer_id' => function () {
                    return Manufacturer::where('name', 'Apple')->first() ?? Manufacturer::factory()->apple();
                },
                'min_amt' => 2,
                //'supplier_id' => Supplier::factory(), //FIXME.
            ];
        });
    }

    public function appleUsbKeyboard()
    {
        self::$quantity = 15;
        return $this->state(function () {
            return [
                'name' => 'USB Keyboard',
                'image' => 'usb-keyboard.jpg',
                'category_id' => function () {
                    return Category::where('name', 'Keyboards')->first() ?? Category::factory()->accessoryKeyboardCategory();
                },
                'manufacturer_id' => function () {
                    return Manufacturer::where('name', 'Apple')->first() ?? Manufacturer::factory()->apple();
                },
                'min_amt' => 2,
                // 'supplier_id' => Supplier::factory(), //FIXME - goes into OrderItems
            ];
        });
    }

    public function appleMouse()
    {
        self::$quantity = 13;
        return $this->state(function () {
            return [
                'name' => 'Magic Mouse',
                'image' => 'magic-mouse.jpg',
                'category_id' => function () {
                    return Category::where('name', 'Mouse')->first() ?? Category::factory()->accessoryMouseCategory();
                },
                'manufacturer_id' => function () {
                    return Manufacturer::where('name', 'Apple')->first() ?? Manufacturer::factory()->apple();
                },
                'min_amt' => 2,
                //'supplier_id' => Supplier::factory(), //FIXME
            ];
        });
    }

    public function microsoftMouse()
    {
        self::$quantity = 13;
        return $this->state(function () {
            return [
                'name' => 'Sculpt Comfort Mouse',
                'image' => 'comfort-mouse.jpg',
                'category_id' => function () {
                    return Category::where('name', 'Mouse')->first() ?? Category::factory()->accessoryMouseCategory();
                },
                'manufacturer_id' => function () {
                    return Manufacturer::where('name', 'Microsoft')->first() ?? Manufacturer::factory()->microsoft();
                },
                'min_amt' => 2,
            ];
        });
    }

    public function withoutItemsRemaining()
    {
        self::$quantity = 1;
        return $this->afterCreating(function ($accessory) {
            \Log::error("AFTER CREATING within the 'withoutItemsRemaing' factory *IS* firing.");
            \Log::error("withoutItemsRemaining numRemaining is: ".$accessory->numRemaining());
            $user = User::factory()->create();

            $accessory->checkouts()->create([
                'accessory_id' => $accessory->id,
                'created_at' => Carbon::now(),
                'created_by' => $user->id,
                'assigned_to' => $user->id,
                'assigned_type' => User::class,
                'note' => '',
                //'quantity' => -1 //wait, this doesn't happen *here*, it happens _elsewhere_
            ]);
            $accessory->assetlog()->create([
                'quantity'         => -1,
                'item_type'        => Accessory::class, //weird?
                'item_id'          => $accessory->id,
                'action_type'      => 'checkout!!!!!!!!!!!!!!!',
                'schnorgleblitzen' => 'FART'
            ]);
            \Log::error("Checkout count for accessory is: ".$accessory->checkouts()->count());
            \Log::error("History log for accessory is: ".$accessory->assetlog()->count());
            \Log::error("FULL HISTORY DUMP IS: ".print_r($accessory->assetlog, true));
            \Log::error("AFTER the final checkout, the num remaining is: ".$accessory->numRemaining());
        });
    }

    public function requiringAcceptance()
    {
        return $this->afterCreating(function ($accessory) {
            $accessory->category->update(['require_acceptance' => 1]);
        });
    }

    public function checkedOutToUser(User $user = null)
    {
        return $this->afterCreating(function (Accessory $accessory) use ($user) {
            $accessory->checkouts()->create([
                'accessory_id' => $accessory->id,
                'created_at' => Carbon::now(),
                'created_by' => 1,
                'assigned_to' => $user->id ?? User::factory()->create()->id,
                'assigned_type' => User::class,
            ]);
        });
    }

    public function checkedOutToUsers(array $users)
    {
        return $this->afterCreating(function (Accessory $accessory) use ($users) {
            foreach ($users as $user) {
                $accessory->checkouts()->create([
                    'accessory_id' => $accessory->id,
                    'created_at' => Carbon::now(),
                    'user_id' => 1,
                    'assigned_to' => $user->id,
                    'assigned_type' => User::class,
                ]);
            }
        });
    }
}
