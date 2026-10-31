<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Seller\Seller;
use App\Services\Buyer\BuyerAddressBook;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class BuyerAddressBookTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, string> */
    private function payload(string $street, string $municipality = 'Lipa City'): array
    {
        return [
            'address_mode' => 'manual',
            'province_name' => 'Batangas',
            'municipality_name' => $municipality,
            'barangay_name' => 'Barangay 1',
            'street' => $street,
        ];
    }

    public function test_buy_addr_001_a_saved_address_is_stored_on_buyer_addresses_and_copied_to_the_buyer(): void
    {
        $buyer = Buyer::factory()->create();

        $this->actingAs($buyer, 'buyer')
            ->patch(route('buyer.account.address.update'), $this->payload('123 Rizal St.'))
            ->assertRedirect(route('buyer.account.address'))
            ->assertSessionHas('success', 'Address updated.');

        $this->assertDatabaseHas('buyer_addresses', [
            'buyer_id' => $buyer->id,
            'street' => '123 Rizal St.',
            'municipality_name' => 'Lipa City',
            'is_default' => true,
            'default_marker' => 1,
        ]);
        $this->assertSame('123 Rizal St.', $buyer->fresh()->street);
        $this->assertSame(1, BuyerAddress::query()->where('buyer_id', $buyer->id)->where('is_default', true)->count());
    }

    public function test_editing_the_default_address_keeps_it_default_and_updates_the_profile(): void
    {
        $buyer = Buyer::factory()->create();
        $this->actingAs($buyer, 'buyer');
        $this->post(route('buyer.account.addresses.store'), $this->payload('Old Street'))->assertRedirect();
        $address = BuyerAddress::query()->where('buyer_id', $buyer->id)->sole();

        $this->patch(route('buyer.account.addresses.update', $address), $this->payload('New Street'))
            ->assertRedirect(route('buyer.account.address'));

        $this->assertTrue($address->fresh()->is_default);
        $this->assertSame(1, $address->fresh()->default_marker);
        $this->assertSame('New Street', $buyer->fresh()->street);
    }

    public function test_buy_addr_003_and_004_a_buyer_can_save_ten_addresses_and_the_eleventh_is_rejected(): void
    {
        $buyer = Buyer::factory()->create();
        $this->actingAs($buyer, 'buyer');

        for ($number = 1; $number <= BuyerAddressBook::MAX; $number++) {
            $this->post(route('buyer.account.addresses.store'), $this->payload("Street {$number}"))
                ->assertRedirect(route('buyer.account.address'))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(BuyerAddressBook::MAX, BuyerAddress::query()->where('buyer_id', $buyer->id)->count());
        $this->assertSame(1, BuyerAddress::query()->where('buyer_id', $buyer->id)->where('is_default', true)->count());

        $this->get(route('buyer.account.address'))
            ->assertOk()
            ->assertSee(BuyerAddressBook::LIMIT_MESSAGE);

        $this->post(route('buyer.account.addresses.store'), $this->payload('Street 11'))
            ->assertSessionHasErrors(['address' => BuyerAddressBook::LIMIT_MESSAGE]);

        $this->assertSame(BuyerAddressBook::MAX, BuyerAddress::query()->where('buyer_id', $buyer->id)->count());
    }

    public function test_buy_addr_005_to_007_exactly_one_default_and_it_cannot_be_deleted_until_another_is_chosen(): void
    {
        $buyer = Buyer::factory()->create();
        $other = Buyer::factory()->create();
        $this->actingAs($buyer, 'buyer');

        $this->post(route('buyer.account.addresses.store'), $this->payload('Home street'))->assertSessionHasNoErrors();
        $this->post(route('buyer.account.addresses.store'), $this->payload('Office street', 'Batangas City'))->assertSessionHasNoErrors();

        $home = BuyerAddress::query()->where('street', 'Home street')->firstOrFail();
        $office = BuyerAddress::query()->where('street', 'Office street')->firstOrFail();
        $this->assertTrue($home->is_default);
        $this->assertFalse($office->is_default);

        $this->get(route('buyer.account.address'))
            ->assertOk()
            ->assertSee('Home street')
            ->assertSee('Office street')
            ->assertSee('Choose another default address before deleting this one.')
            ->assertSee('Set as default');

        $this->delete(route('buyer.account.addresses.destroy', $home))
            ->assertSessionHasErrors(['address' => BuyerAddressBook::DEFAULT_DELETE_MESSAGE]);
        $this->assertNotNull($home->fresh());
        $this->assertSame('Home street', $buyer->fresh()->street);

        $this->post(route('buyer.account.addresses.default', $office))
            ->assertRedirect()
            ->assertSessionHas('success', 'Default address updated.');

        $this->assertFalse($home->fresh()->is_default);
        $this->assertTrue($office->fresh()->is_default);
        $this->assertSame(1, BuyerAddress::query()->where('buyer_id', $buyer->id)->where('is_default', true)->count());
        $this->assertSame('Office street', $buyer->fresh()->street);
        $this->assertSame('Batangas City', $buyer->fresh()->municipality_name);

        $this->delete(route('buyer.account.addresses.destroy', $home))
            ->assertRedirect()
            ->assertSessionHas('success', 'Address deleted.');

        $this->assertNull($home->fresh());
        $this->assertTrue($office->fresh()->is_default);
        $this->assertSame(1, BuyerAddress::query()->where('buyer_id', $buyer->id)->count());

        $this->actingAs($other, 'buyer')
            ->delete(route('buyer.account.addresses.destroy', $office))
            ->assertNotFound();
        $this->assertNotNull($office->fresh());
    }

    /** @return array{0: Buyer, 1: BuyerAddress, 2: BuyerAddress} The owner, their default and their other address. */
    private function otherBuyersBook(): array
    {
        $owner = Buyer::factory()->create();
        $book = app(BuyerAddressBook::class);

        return [
            $owner,
            $book->save($owner, $this->payload('Owner home')),
            $book->save($owner, $this->payload('Owner office')),
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, string>}> */
    public static function addressRoutes(): array
    {
        return [
            'edit' => ['patch', 'buyer.account.addresses.update', ['street' => 'Stolen street', 'address_mode' => 'manual', 'municipality_name' => 'Lipa City']],
            'edit with invalid fields' => ['patch', 'buyer.account.addresses.update', []],
            'set as default' => ['post', 'buyer.account.addresses.default', []],
            'delete' => ['delete', 'buyer.account.addresses.destroy', []],
        ];
    }

    /** @param array<string, string> $payload */
    #[DataProvider('addressRoutes')]
    public function test_buy_addr_009_another_buyers_address_answers_not_found_and_stays_unchanged(string $method, string $route, array $payload): void
    {
        [$owner, $home, $office] = $this->otherBuyersBook();
        $intruder = Buyer::factory()->create();
        $own = app(BuyerAddressBook::class)->save($intruder, $this->payload('Intruder home'));
        $before = $office->fresh()->toArray();

        $this->actingAs($intruder, 'buyer');

        $this->{$method}(route($route, $office), $payload)->assertNotFound();

        $this->assertSame($before, $office->fresh()->toArray());
        $this->assertTrue($home->fresh()->is_default);
        $this->assertSame('Owner home', $owner->fresh()->street);
        $this->assertTrue($own->fresh()->is_default);
        $this->assertSame('Intruder home', $intruder->fresh()->street);
        $this->assertSame(1, BuyerAddress::query()->where('buyer_id', $intruder->id)->count());
    }

    #[DataProvider('addressRoutes')]
    public function test_buy_addr_009_someone_elses_id_looks_the_same_as_one_that_does_not_exist(string $method, string $route): void
    {
        [, , $office] = $this->otherBuyersBook();
        $this->actingAs(Buyer::factory()->create(), 'buyer');
        $this->withoutVite();
        // Production settings: debug pages name the model and differ on purpose.
        config(['app.debug' => false]);

        $foreign = $this->{$method}(route($route, $office));
        $missing = $this->{$method}(route($route, 999999));

        $foreign->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($missing->getContent(), $foreign->getContent());
    }

    #[DataProvider('addressRoutes')]
    public function test_buy_addr_009_only_a_signed_in_buyer_reaches_the_route(string $method, string $route, array $payload): void
    {
        [, , $office] = $this->otherBuyersBook();
        $before = $office->fresh()->toArray();

        $this->{$method}(route($route, $office), $payload)->assertRedirect();
        $this->assertSame($before, $office->fresh()->toArray());

        $this->actingAs(Seller::factory()->create(['status' => 'approved']), 'seller');

        $this->{$method}(route($route, $office), $payload)->assertRedirect();
        $this->assertSame($before, $office->fresh()->toArray());
    }

    public function test_buy_addr_009_the_edit_form_does_not_open_another_buyers_address(): void
    {
        [, , $office] = $this->otherBuyersBook();
        $this->actingAs(Buyer::factory()->create(), 'buyer');

        $this->get(route('buyer.account.address', ['edit' => $office->id]))->assertNotFound();
        $this->get(route('buyer.account.address', ['edit' => 'abc']))->assertNotFound();
        $this->get(route('buyer.account.address'))->assertOk()->assertDontSee('Owner office');
    }

    public function test_buy_addr_009_the_form_cannot_write_to_another_buyer_or_move_an_address(): void
    {
        [$owner, , $office] = $this->otherBuyersBook();
        $intruder = Buyer::factory()->create();
        $own = app(BuyerAddressBook::class)->save($intruder, $this->payload('Intruder home'));
        $this->actingAs($intruder, 'buyer');

        $this->post(route('buyer.account.addresses.store'), $this->payload('Planted street') + ['buyer_id' => $owner->id])
            ->assertRedirect();
        $this->patch(route('buyer.account.addresses.update', $own), $this->payload('Intruder moved') + ['buyer_id' => $owner->id])
            ->assertRedirect();
        $this->patch(route('buyer.account.address.update'), $this->payload('Intruder default') + ['id' => $office->id, 'buyer_id' => $owner->id])
            ->assertRedirect();

        $this->assertSame(2, BuyerAddress::query()->where('buyer_id', $owner->id)->count());
        $this->assertSame('Owner office', $office->fresh()->street);
        $this->assertSame($intruder->id, $own->fresh()->buyer_id);
        $this->assertSame(2, BuyerAddress::query()->where('buyer_id', $intruder->id)->count());
    }

    public function test_buy_addr_009_the_address_book_refuses_a_foreign_address_on_its_own(): void
    {
        [, , $office] = $this->otherBuyersBook();
        $intruder = Buyer::factory()->create();
        $book = app(BuyerAddressBook::class);

        foreach ([
            fn () => $book->save($intruder, $this->payload('Stolen street'), $office),
            fn () => $book->makeDefault($intruder, $office),
            fn () => $book->delete($intruder, $office),
        ] as $call) {
            try {
                $call();
                $this->fail('A foreign address was accepted.');
            } catch (NotFoundHttpException) {
                $this->assertSame('Owner office', $office->fresh()->street);
                $this->assertFalse($office->fresh()->is_default);
            }
        }
    }
}
