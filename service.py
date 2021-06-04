import json
import base64
import requests


class ShopifyConverter:
    def __init__(self) -> None:
        self.headers = {
            'Content-Type' : 'application/json'
        }
        self.main_url ="https://13c12d25dc4c2cf70852f186f6f5ff4b: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@arzuhussein.myshopify.com/"
        self.product_url=f'{self.main_url}/admin/api/2021-04/products.json'

    def __prepare(self, data: dict) -> dict:
        return [{
            "product" : {
            "title": p['name'],
            "body_html": p['description'],
            "variants" : [
                {
                "option1": p['reference'],
                "price": p['price'],
                "sku": p['quantity'],
                },
            ],
            "vendor": p['vendor'],
            "image_url" : p['image_url'],
            "product_type": p['categories'],
            "published": True if p['active'] == "1" else False}
        } for p in data]

    def __post(self, body: str, url: str) -> dict:
        payload = requests.post(url, json=body, headers=self.headers)
        return json.loads(payload.text)
    
    def __generate_base64_from_url(self, url: str) -> str:
        return base64.b64encode(requests.get(url).content).decode()
    
    def __generate_image_name(self, name: str)-> str:
        obj_name = name.lower().split(" ")
        image_name = "-".join(s for s in obj_name)
        return f'{image_name}.png'

    def __create_product_img(self, image_url: str, file_name: str) -> dict:
        return { 
            "image": {
                "position": 1,
                "attachment": self.__generate_base64_from_url(image_url),
                "filename"  : self.__generate_image_name(file_name)
        }}

    def push(self, data: list) -> list:
        products=self.__prepare(data)
        created_products = []
        for product in products:
            payload = self.__post(product, self.product_url)
            if payload.get('product') and product['product']['image_url']:
                url = f"{self.main_url}/admin/api/2021-04/products/{payload.get('product')['id']}/images.json"
       
                img_payload = self.__create_product_img(
                    image_url=product['product']['image_url'],
                    file_name=payload.get('product')['title'],
                )
                img_created = self.__post(img_payload, url)
                if img_created:
                    payload.get('product').update({'image' : img_created})

            created_products.append(payload.get('product'))
        return created_products



shopify_converter = ShopifyConverter()