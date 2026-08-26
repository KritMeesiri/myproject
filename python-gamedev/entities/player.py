import arcade

PLAYER_HEALTH = 10
PLAYER_SIZE = 100
PLAYER_MOVEMENT_SPEED = 20
PLAYER_MAX_SPEED = 3.0
PLAYER_ACCEL_RATE = 0.1
PLAYER_FRICTION = 0.02


class Player(arcade.Sprite):
    def __init__(self, x, y):

        super().__init__(arcade.make_circle_texture(PLAYER_SIZE, arcade.color.BLUE))
        self.center_x = x
        self.center_y = y
        self.health = PLAYER_HEALTH
        self.score = 0
        self.alive = True

        # Track the current state of what key is pressed
        self.left_pressed = False
        self.right_pressed = False
        self.up_pressed = False
        self.down_pressed = False

    def update(self, delta_time: float = 1 / 60, *args, **kwargs) -> None:
        """Move the player"""
        # Move player.
        self.center_x += self.change_x
        self.center_y += self.change_y

    def update_speed(self):
        self.change_x = 0
        self.change_y = 0

        if self.up_pressed and not self.down_pressed:
            self.change_y = PLAYER_MOVEMENT_SPEED
        elif self.down_pressed and not self.up_pressed:
            self.change_y = -PLAYER_MOVEMENT_SPEED
        if self.left_pressed and not self.right_pressed:
            self.change_x = -PLAYER_MOVEMENT_SPEED
        elif self.right_pressed and not self.left_pressed:
            self.change_x = PLAYER_MOVEMENT_SPEED

    def movement_press(self, key):
        """Called whenever a key is pressed."""

        if key == arcade.key.UP:
            self.up_pressed = True
            self.update_speed()
        elif key == arcade.key.DOWN:
            self.down_pressed = True
            self.update_speed()
        elif key == arcade.key.LEFT:
            self.left_pressed = True
            self.update_speed()
        elif key == arcade.key.RIGHT:
            self.right_pressed = True
            self.update_speed()

    def movement_release(self, key):
        """Called whenever a key is released."""

        if key == arcade.key.UP:
            self.up_pressed = False
            self.update_speed()
        elif key == arcade.key.DOWN:
            self.down_pressed = False
            self.update_speed()
        elif key == arcade.key.LEFT:
            self.left_pressed = False
            self.update_speed()
        elif key == arcade.key.RIGHT:
            self.right_pressed = False
            self.update_speed()
