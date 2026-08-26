import arcade
from entities.player import Player
from entities.enemy import Enemy
from pyglet.graphics import Batch

WIDTH_WINDOW = 1600
HEIGHT_WINDOW = 900
WINDOW_TITLE = "Bullet Hell"


class GameView(arcade.View):
    """Main game class"""

    def __init__(self):
        super().__init__()
        self.background_color = arcade.csscolor.GRAY
        self.players = None
        self.enemies = None
        self.camera = None
        self.gui = None

    def setup(self):
        """Game setup. Call to restart the game."""
        """Player setup. """
        self.players = arcade.SpriteList()
        self.player = Player(x=self.center_x, y=self.center_y + 50)
        self.players.append(self.player)

        """Enemies setup."""
        self.enemies = arcade.SpriteList()
        self.enemy = Enemy(self.center_x, y=HEIGHT_WINDOW - 100)
        self.enemies.append(self.enemy)

        """Game UI setup. """
        self.camera = arcade.Camera2D()
        self.gui = arcade.Camera2D()

        # Text batch. For better performance
        self.text_batch = Batch()
        self.player_health = arcade.Text(
            f"Health = {self.player.health}", 5, 5, batch=self.text_batch
        )
        self.player_score = arcade.Text(
            f"Score = {self.player.score}",
            self.player_health.right + 5,
            5,
            batch=self.text_batch,
        )

        """Setup Physics Engine."""

    def on_draw(self):
        """clear() always at the top of on_draw()."""
        self.clear()
        """ Draw after this. """
        self.players.draw()
        self.enemies.draw()
        self.enemy.bullet_list.draw()
        self.gui.use()
        self.text_batch.draw()

    def on_update(self, delta_time):
        # Movement and game logic
        self.enemy.shoot(delta_time)
        self.enemy.bullet_list.update()
        self.players.update(delta_time)

        # Check if player is alive
        for player in self.players:
            if player.alive:
                # Generate a list of all sprites that collied with the player
                bullet_hit_list = arcade.check_for_collision_with_list(
                    self.player, self.enemy.bullet_list
                )

                # Loop through each colliding sprite and remove it
                for bullet in bullet_hit_list:
                    bullet.remove_from_sprite_lists()
                    self.player.health -= 1
                    self.player_health.text = f"Health = {player.health}"

            # Check for out-of-bounds bullets and count score
            for bullet in self.enemy.bullet_list:
                if bullet.top < 0:
                    bullet.remove_from_sprite_lists()
                    player.score += 1
                    self.player_score.text = f"Score = {player.score}"

                # Check player's health
                if player.health <= 0:
                    player.remove_from_sprite_lists()
                    player.alive = False
                    endview = EndView()
                    self.window.show_view(endview)

        # Check for out-of-bounds player
        if self.player.left < 0:
            self.player.left = 0
        elif self.player.right > WIDTH_WINDOW - 1:
            self.player.right = WIDTH_WINDOW - 1

        if self.player.bottom < 0:
            self.player.bottom = 0
        elif self.player.top > HEIGHT_WINDOW - 200:
            self.player.top = HEIGHT_WINDOW - 200

    def on_key_press(self, key, modifiers):
        self.player.movement_press(key)

    def on_key_release(self, key: int, modifiers: int):
        self.player.movement_release(key)


class StartView(arcade.View):
    def __init__(self):
        super().__init__()
        self.start_title = arcade.Text(
            "Press any key to start", self.center_x, self.center_y, arcade.color.BLACK
        )

    def on_show_view(self):
        self.window.background_color = arcade.color.WHITE

    def on_draw(self):
        self.clear()
        self.start_title.draw()

    def on_key_press(self, symbol: int, modifiers: int) -> bool | None:
        gameview = GameView()
        gameview.setup()
        self.window.show_view(gameview)


class EndView(arcade.View):
    def on_show_view(self) -> None:
        self.window.background_color = arcade.color.BLACK
        self.end_died = arcade.Text(
            "You Died", self.center_x, self.center_y, arcade.color.RED
        )
        self.end_text = arcade.Text(
            "Press to restart", self.center_x, self.center_y - 50, arcade.color.RED
        )

    def on_draw(self) -> bool | None:
        self.clear()
        self.end_died.draw()
        self.end_text.draw()

    def on_key_press(self, symbol: int, modifiers: int) -> bool | None:
        gameview = GameView()
        gameview.setup()
        self.window.show_view(gameview)


def main():
    """Main function."""
    window = arcade.Window(WIDTH_WINDOW, HEIGHT_WINDOW, WINDOW_TITLE)
    startview = StartView()
    window.show_view(startview)
    arcade.run()


if __name__ == "__main__":
    main()
