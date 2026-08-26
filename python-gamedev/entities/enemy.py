import arcade
import random
import math

ENEMY_SIZE = 100
SHOOTING_ODDS = 100
BULLET_SPEED = 50


class Enemy(arcade.Sprite):
    def __init__(self, x, y):
        """Initialize Enemy."""
        super().__init__(arcade.make_circle_texture(ENEMY_SIZE, arcade.color.RED))
        self.center_x = x
        self.center_y = y
        self.bullet_list = arcade.SpriteList()

    def shoot(self, delta_time):
        """Shooting mechanic at random interval."""
        adj_odds = int(SHOOTING_ODDS * (1 / 60) / delta_time)

        if random.randrange(adj_odds) == 0:
            angle = math.radians(random.uniform(-112.5, -67.5))
            bullet_texture = arcade.make_circle_texture(50, arcade.color.YELLOW)
            bullet = arcade.Sprite(bullet_texture)
            bullet.center_x = self.center_x
            bullet.top = self.bottom
            bullet.change_x = math.cos(angle) * BULLET_SPEED
            bullet.change_y = math.sin(angle) * BULLET_SPEED
            self.bullet_list.append(bullet)

        for bullet in self.bullet_list:
            if bullet.top < 0:
                bullet.remove_from_sprite_lists()

        self.bullet_list.update()
