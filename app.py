import json

from flask import Flask, request

from service import shopify_converter
  

app = Flask(__name__)
  

@app.route('/', methods=['GET', 'POST'])
def main_endpoint():
    if request.method == 'POST':
        data = json.loads(request.form.get("products"))
        if data:
            results = shopify_converter.push(data)
            print(results)
    return 'Done'
  

if __name__ == '__main__':
  
  
    app.run(debug=True)
